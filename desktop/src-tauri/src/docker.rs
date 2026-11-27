use std::io::{BufRead, BufReader, Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::thread::JoinHandle;
use std::time::Duration;

pub const MANAGER_ADDR: &str = "127.0.0.1:8080";

/// Apps opened from Finder/Explorer don't get the shell's PATH, so look in the usual install places too.
fn extra_paths() -> Vec<PathBuf> {
    let mut paths = vec![];
    if cfg!(target_os = "macos") {
        paths.push(PathBuf::from("/usr/local/bin"));
        paths.push(PathBuf::from("/opt/homebrew/bin"));
        paths.push(PathBuf::from("/Applications/Docker.app/Contents/Resources/bin"));
        if let Some(home) = std::env::var_os("HOME") {
            paths.push(PathBuf::from(home).join(".docker/bin"));
        }
    } else if cfg!(windows) {
        paths.push(PathBuf::from(r"C:\Program Files\Docker\Docker\resources\bin"));
    } else {
        paths.push(PathBuf::from("/usr/local/bin"));
        paths.push(PathBuf::from("/usr/bin"));
    }
    paths
}

/// PATH for child processes: current PATH plus the Docker locations (compose and credential helpers need them).
fn child_path() -> std::ffi::OsString {
    let mut all: Vec<PathBuf> = std::env::var_os("PATH")
        .map(|p| std::env::split_paths(&p).collect())
        .unwrap_or_default();
    all.extend(extra_paths());
    std::env::join_paths(all).unwrap_or_default()
}

pub fn docker_bin() -> Option<PathBuf> {
    let name = if cfg!(windows) { "docker.exe" } else { "docker" };
    std::env::split_paths(&child_path())
        .map(|dir| dir.join(name))
        .find(|p| p.is_file())
}

pub fn docker_command(dir: Option<&Path>) -> Option<Command> {
    let mut cmd = Command::new(docker_bin()?);
    cmd.env("PATH", child_path());
    if let Some(dir) = dir {
        cmd.current_dir(dir);
    }
    #[cfg(windows)]
    {
        use std::os::windows::process::CommandExt;
        cmd.creation_flags(0x0800_0000); // CREATE_NO_WINDOW
    }
    Some(cmd)
}

pub fn docker_running() -> bool {
    docker_command(None)
        .map(|mut cmd| {
            cmd.args(["info", "--format", "{{.ServerVersion}}"])
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status()
                .map(|s| s.success())
                .unwrap_or(false)
        })
        .unwrap_or(false)
}

/// What answers on 127.0.0.1:8080: nothing, the Manager, or some other program.
#[derive(serde::Serialize, Clone, Copy, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum Port {
    Closed,
    Manager,
    Other,
}

pub fn manager_port() -> Port {
    let addr: SocketAddr = MANAGER_ADDR.parse().unwrap();
    let Ok(mut stream) = TcpStream::connect_timeout(&addr, Duration::from_millis(800)) else {
        return Port::Closed;
    };
    let _ = stream.set_read_timeout(Some(Duration::from_secs(3)));
    let request = format!("GET /api/session HTTP/1.0\r\nHost: {MANAGER_ADDR}\r\nConnection: close\r\n\r\n");
    if stream.write_all(request.as_bytes()).is_err() {
        return Port::Other;
    }
    let mut body = String::new();
    let _ = stream.take(64 * 1024).read_to_string(&mut body);
    if body.starts_with("HTTP/1.") && body.contains(" 200 ") && body.contains("\"csrf_token\"") {
        Port::Manager
    } else if body.is_empty() {
        // The PHP server accepted the socket but has not answered yet (still starting).
        Port::Closed
    } else {
        Port::Other
    }
}

pub fn is_project_dir(dir: &Path) -> bool {
    std::fs::read_to_string(dir.join("docker-compose.yml"))
        .map(|yml| yml.contains("manager_container"))
        .unwrap_or(false)
}

/// The only compose commands the app runs. Arguments are fixed here, never taken from the webview.
pub fn compose_args(action: &str) -> Option<&'static [&'static str]> {
    match action {
        "up" => Some(&["compose", "up", "-d"]),
        "build" => Some(&["compose", "build"]),
        "stop" => Some(&["compose", "stop"]),
        "restart" => Some(&["compose", "restart"]),
        _ => None,
    }
}

/// The only per-container actions (`docker compose <action> <service>`).
pub fn service_args(action: &str) -> Option<&'static str> {
    match action {
        "start" => Some("start"),
        "restart" => Some("restart"),
        "stop" => Some("stop"),
        _ => None,
    }
}

/// Starts `docker …args` in `dir` with stdout and stderr piped.
fn spawn_piped(dir: Option<&Path>, args: &[&str]) -> Result<Child, String> {
    docker_command(dir)
        .ok_or("docker not found")?
        .args(args)
        .env("COMPOSE_ANSI", "never")
        .env("COMPOSE_PROGRESS", "plain")
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| e.to_string())
}

/// Sends every stdout/stderr line of `child` to `on_line` from two background threads (they end at EOF).
fn pipe_lines(child: &mut Child, on_line: impl Fn(String) + Send + Sync + 'static) -> [JoinHandle<()>; 2] {
    let on_line = std::sync::Arc::new(on_line);
    let (out_line, err_line) = (on_line.clone(), on_line);
    let stdout = child.stdout.take().unwrap();
    let stderr = child.stderr.take().unwrap();
    [
        std::thread::spawn(move || BufReader::new(stdout).lines().map_while(Result::ok).for_each(|l| out_line(l))),
        std::thread::spawn(move || BufReader::new(stderr).lines().map_while(Result::ok).for_each(|l| err_line(l))),
    ]
}

/// Runs `docker …args`, calling `on_line` for every output line (stdout and stderr). Returns the exit code.
pub fn run_streaming(dir: Option<&Path>, args: &[&str], on_line: impl Fn(String) + Send + Sync + 'static) -> Result<i32, String> {
    let mut child = spawn_piped(dir, args)?;
    for reader in pipe_lines(&mut child, on_line) {
        let _ = reader.join();
    }
    let status = child.wait().map_err(|e| e.to_string())?;
    Ok(status.code().unwrap_or(-1))
}

/// Runs `docker compose …` in `dir` for one of the fixed actions. Returns the exit code.
pub fn run_compose(dir: &Path, action: &str, on_line: impl Fn(String) + Send + Sync + 'static) -> Result<i32, String> {
    let args = compose_args(action).ok_or_else(|| format!("unknown action: {action}"))?;
    run_streaming(Some(dir), args, on_line)
}

/// `docker compose logs -f` for one service; the caller kills the returned child to stop following.
pub fn follow_logs(dir: &Path, service: &str, on_line: impl Fn(String) + Send + Sync + 'static) -> Result<Child, String> {
    check_service(dir, service)?;
    let mut child = spawn_piped(Some(dir), &["compose", "logs", "-f", "--no-color", "--tail", "200", service])?;
    pipe_lines(&mut child, on_line);
    Ok(child)
}

#[derive(serde::Serialize, Clone, Debug, PartialEq, Eq)]
pub struct Service {
    pub name: String,
    pub container: String,
    pub state: String,
    pub status: String,
}

/// `compose ps --format json` prints a JSON array on older Compose and one object per line on newer ones.
fn parse_services(out: &str) -> Vec<Service> {
    let rows: Vec<serde_json::Value> = if out.trim_start().starts_with('[') {
        serde_json::from_str(out).unwrap_or_default()
    } else {
        out.lines().filter_map(|l| serde_json::from_str(l).ok()).collect()
    };
    let field = |row: &serde_json::Value, key: &str| row.get(key).and_then(|v| v.as_str()).unwrap_or_default().to_string();
    let mut services: Vec<Service> = rows
        .iter()
        .map(|row| Service {
            name: field(row, "Service"),
            container: field(row, "Name"),
            state: field(row, "State"),
            status: field(row, "Status"),
        })
        .filter(|s| !s.name.is_empty())
        .collect();
    services.sort_by(|a, b| a.name.cmp(&b.name));
    services
}

/// Containers of the stack in `dir`, running or not.
pub fn services(dir: &Path) -> Vec<Service> {
    docker_command(Some(dir))
        .and_then(|mut cmd| cmd.args(["compose", "ps", "-a", "--format", "json"]).stderr(Stdio::null()).output().ok())
        .map(|out| parse_services(&String::from_utf8_lossy(&out.stdout)))
        .unwrap_or_default()
}

/// Names coming from the webview must be one of the stack's own services (also keeps them shell-safe).
pub fn check_service(dir: &Path, service: &str) -> Result<(), String> {
    if services(dir).iter().any(|s| s.name == service) {
        Ok(())
    } else {
        Err(format!("unknown_service|{service}"))
    }
}

#[derive(serde::Serialize)]
pub struct Stat {
    pub container: String,
    pub cpu: String,
    pub mem: String,
}

/// One CPU/RAM sample of the given containers (`docker stats --no-stream` takes ~2 s).
pub fn stats(containers: &[String]) -> Vec<Stat> {
    if containers.is_empty() {
        return vec![];
    }
    let Some(mut cmd) = docker_command(None) else { return vec![] };
    let Ok(out) = cmd.args(["stats", "--no-stream", "--format", "{{json .}}"]).args(containers).stderr(Stdio::null()).output() else {
        return vec![];
    };
    parse_stats(&String::from_utf8_lossy(&out.stdout))
}

fn parse_stats(out: &str) -> Vec<Stat> {
    out.lines()
        .filter_map(|l| serde_json::from_str::<serde_json::Value>(l).ok())
        .map(|row| {
            let field = |key: &str| row.get(key).and_then(|v| v.as_str()).unwrap_or_default().to_string();
            // MemUsage is "45.3MiB / 7.6GiB": the used part is enough.
            let mem = field("MemUsage").split(" / ").next().unwrap_or_default().to_string();
            Stat { container: field("Name"), cpu: field("CPUPerc"), mem }
        })
        .collect()
}

/// A site from env.json that can be opened in the browser.
#[derive(Clone, PartialEq, Eq)]
pub struct Site {
    pub name: String,
    pub url: String,
}

pub fn sites(dir: &Path) -> Vec<Site> {
    let Ok(text) = std::fs::read_to_string(dir.join("env.json")) else { return vec![] };
    parse_sites(&text)
}

fn parse_sites(text: &str) -> Vec<Site> {
    let Ok(serde_json::Value::Object(servers)) = serde_json::from_str(text) else { return vec![] };
    let mut sites: Vec<Site> = servers
        .values()
        .filter(|s| s.get("ENABLED").and_then(|v| v.as_bool()).unwrap_or(true))
        .filter_map(|s| {
            let domain = s.get("DOMAIN_NAME")?.as_str()?.trim();
            // Only plain host names end up in a URL.
            if domain.is_empty() || !domain.chars().all(|c| c.is_ascii_alphanumeric() || c == '.' || c == '-') {
                return None;
            }
            let ssl = s.get("SSL_ENABLED").and_then(|v| v.as_bool()).unwrap_or(false);
            Some(Site { name: domain.to_string(), url: format!("{}://{domain}/", if ssl { "https" } else { "http" }) })
        })
        .collect();
    sites.sort_by(|a, b| a.name.cmp(&b.name));
    sites
}

fn sh_quote(s: &str) -> String {
    format!("'{}'", s.replace('\'', "'\\''"))
}

/// Opens the OS terminal with a shell inside `service` (bash when the image has it, else sh).
pub fn open_terminal(dir: &Path, service: &str) -> Result<(), String> {
    check_service(dir, service)?;
    let shell = "command -v bash >/dev/null && exec bash || exec sh";
    let started = if cfg!(target_os = "macos") {
        let script = format!("cd {} && docker compose exec {service} sh -c {}", sh_quote(&dir.display().to_string()), sh_quote(shell));
        let script = script.replace('\\', "\\\\").replace('"', "\\\"");
        Command::new("osascript")
            .args(["-e", &format!("tell application \"Terminal\" to do script \"{script}\""), "-e", "tell application \"Terminal\" to activate"])
            .status()
            .is_ok_and(|s| s.success())
    } else if cfg!(windows) {
        let mut cmd = hidden(Command::new("cmd"));
        cmd.current_dir(dir);
        #[cfg(windows)]
        {
            use std::os::windows::process::CommandExt;
            // ponytail: Windows quoting only checked by reading, not run; the quoted sh -c part keeps cmd away from && and >.
            cmd.raw_arg(format!("/c start \"Multi PHP - {service}\" cmd /k docker compose exec {service} sh -c \"{shell}\""));
        }
        cmd.status().is_ok_and(|s| s.success())
    } else {
        Command::new("x-terminal-emulator")
            .current_dir(dir)
            .args(["-e", "docker", "compose", "exec", service, "sh", "-c", shell])
            .spawn()
            .is_ok()
    };
    if started { Ok(()) } else { Err("terminal_failed".into()) }
}

/// Opens the project folder in VS Code.
pub fn open_vscode(dir: &Path) -> Result<(), String> {
    let ok = if cfg!(target_os = "macos") {
        Command::new("open").args(["-a", "Visual Studio Code"]).arg(dir).status().is_ok_and(|s| s.success())
    } else if cfg!(windows) {
        hidden(Command::new("cmd")).args(["/c", "code"]).arg(dir).status().is_ok_and(|s| s.success())
    } else {
        Command::new("code").arg(dir).spawn().is_ok()
    };
    if ok { Ok(()) } else { Err("vscode_not_found".into()) }
}

fn hidden(mut cmd: Command) -> Command {
    #[cfg(windows)]
    {
        use std::os::windows::process::CommandExt;
        cmd.creation_flags(0x0800_0000); // CREATE_NO_WINDOW
    }
    cmd.env("PATH", child_path());
    cmd
}

/// Name (and PID) of the program listening on the Manager port, to tell the user what to close.
pub fn port_owner() -> Option<String> {
    let port = MANAGER_ADDR.rsplit(':').next()?;
    if cfg!(windows) {
        let out = hidden(Command::new("netstat")).args(["-ano", "-p", "TCP"]).output().ok()?;
        let pid = String::from_utf8_lossy(&out.stdout)
            .lines()
            .find(|l| l.contains("LISTENING") && l.split_whitespace().nth(1).is_some_and(|a| a.ends_with(&format!(":{port}"))))?
            .split_whitespace()
            .last()?
            .to_string();
        let out = hidden(Command::new("tasklist")).args(["/FI", &format!("PID eq {pid}"), "/FO", "CSV", "/NH"]).output().ok()?;
        let name = String::from_utf8_lossy(&out.stdout).split(',').next()?.trim().trim_matches('"').to_string();
        return Some(if name.is_empty() { format!("PID {pid}") } else { format!("{name} (PID {pid})") });
    }
    let out = hidden(Command::new("lsof")).args(["-nP", &format!("-iTCP:{port}"), "-sTCP:LISTEN", "-Fpc"]).output().ok()?;
    parse_lsof(&String::from_utf8_lossy(&out.stdout))
}

fn parse_lsof(out: &str) -> Option<String> {
    let pid = out.lines().find_map(|l| l.strip_prefix('p'))?;
    let name = out.lines().find_map(|l| l.strip_prefix('c')).unwrap_or("?");
    Some(format!("{name} (PID {pid})"))
}

/// The .app bundle the `docker` CLI ships with (Docker Desktop, OrbStack, Rancher Desktop, …), found via its symlink.
pub fn engine_app(docker: &Path) -> Option<PathBuf> {
    let real = std::fs::canonicalize(docker).ok()?;
    real.ancestors()
        .find(|p| p.extension().is_some_and(|e| e == "app"))
        .map(Path::to_path_buf)
}

fn quiet_status(cmd: &mut Command) -> bool {
    cmd.env("PATH", child_path())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|s| s.success())
}

/// Starts the engine in the background, without bringing its window up.
pub fn open_docker_desktop() -> Result<(), String> {
    if cfg!(target_os = "macos") {
        if let Some(app) = docker_bin().as_deref().and_then(engine_app) {
            // The engine's own headless start first; `open -g -j` (background, hidden) if that is missing or fails.
            let orbctl = app.join("Contents/MacOS/bin/orbctl");
            let started = if orbctl.is_file() {
                quiet_status(Command::new(&orbctl).arg("start"))
            } else if app.file_name().is_some_and(|n| n == "Docker.app") {
                docker_command(None).is_some_and(|mut c| quiet_status(c.args(["desktop", "start", "--detach"])))
            } else {
                false
            };
            if started || quiet_status(Command::new("open").args(["-g", "-j"]).arg(&app)) {
                return Ok(());
            }
            return Err(format!("docker_start_failed|{}", app.display()));
        }
        // CLI not inside an app bundle (e.g. Homebrew): try the usual engines.
        for name in ["Docker", "OrbStack", "Rancher Desktop"] {
            if quiet_status(Command::new("open").args(["-g", "-j", "-a", name])) {
                return Ok(());
            }
        }
        return Err("docker_app_not_found".into());
    }
    let result = if cfg!(windows) {
        Command::new(r"C:\Program Files\Docker\Docker\Docker Desktop.exe").spawn().map(|_| std::process::ExitStatus::default())
    } else {
        return Err("docker_manual".into());
    };
    match result {
        Ok(s) if s.success() => Ok(()),
        Ok(_) => Err("docker_start_failed|Docker Desktop".into()),
        Err(e) => Err(format!("docker_start_failed|Docker Desktop: {e}")),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_fixed_actions() {
        assert!(compose_args("up").is_some());
        assert!(compose_args("stop").is_some());
        assert!(compose_args("restart").is_some());
        assert!(compose_args("down").is_none());
        assert!(compose_args("up; rm -rf /").is_none());
    }

    #[test]
    fn parses_both_ps_formats() {
        let lines = "{\"Service\":\"php82\",\"State\":\"running\",\"Status\":\"Up 2 hours\"}\n{\"Service\":\"manager\",\"State\":\"exited\",\"Status\":\"Exited (0)\"}\n";
        let array = "[{\"Service\":\"php82\",\"State\":\"running\",\"Status\":\"Up 2 hours\"},{\"Service\":\"manager\",\"State\":\"exited\",\"Status\":\"Exited (0)\"}]";
        let got = parse_services(lines);
        assert_eq!(got, parse_services(array));
        assert_eq!(got.iter().map(|s| s.name.as_str()).collect::<Vec<_>>(), ["manager", "php82"]);
        assert_eq!(got[0].state, "exited");
        assert!(parse_services("").is_empty());
    }

    #[test]
    fn parses_stats_and_sites() {
        let got = parse_stats("{\"Name\":\"php82_container\",\"CPUPerc\":\"0.31%\",\"MemUsage\":\"45.3MiB / 7.6GiB\"}\n");
        assert_eq!((got[0].container.as_str(), got[0].cpu.as_str(), got[0].mem.as_str()), ("php82_container", "0.31%", "45.3MiB"));
        let env = r#"{"A":{"DOMAIN_NAME":"b.test","ENABLED":true,"SSL_ENABLED":true},
            "B":{"DOMAIN_NAME":"a.test"},"C":{"DOMAIN_NAME":"off.test","ENABLED":false},
            "D":{"DOMAIN_NAME":""},"E":{"DOMAIN_NAME":"x.test\"; rm"}}"#;
        let urls: Vec<String> = parse_sites(env).into_iter().map(|s| s.url).collect();
        assert_eq!(urls, ["http://a.test/", "https://b.test/"]);
        assert!(parse_sites("[]").is_empty());
        assert_eq!(sh_quote("it's"), "'it'\\''s'");
        assert!(service_args("start").is_some() && service_args("rm").is_none());
    }

    #[test]
    fn lsof_owner() {
        assert_eq!(parse_lsof("p812\ncnode\nf23\n").as_deref(), Some("node (PID 812)"));
        assert_eq!(parse_lsof(""), None);
    }

    #[cfg(unix)]
    #[test]
    fn engine_app_follows_symlink() {
        let tmp = std::env::temp_dir().join(format!("mpd-app-{}", std::process::id()));
        let bin = tmp.join("OrbStack.app/Contents/MacOS/xbin");
        std::fs::create_dir_all(&bin).unwrap();
        std::fs::write(bin.join("docker"), "").unwrap();
        std::os::unix::fs::symlink(bin.join("docker"), tmp.join("docker")).unwrap();
        assert_eq!(engine_app(&tmp.join("docker")), Some(std::fs::canonicalize(tmp.join("OrbStack.app")).unwrap()));
        std::fs::write(tmp.join("plain"), "").unwrap();
        assert_eq!(engine_app(&tmp.join("plain")), None);
        std::fs::remove_dir_all(&tmp).unwrap();
    }

    #[test]
    fn project_dir_needs_manager_compose() {
        let tmp = std::env::temp_dir().join(format!("mpd-test-{}", std::process::id()));
        std::fs::create_dir_all(&tmp).unwrap();
        assert!(!is_project_dir(&tmp));
        std::fs::write(tmp.join("docker-compose.yml"), "services: {}").unwrap();
        assert!(!is_project_dir(&tmp));
        std::fs::write(tmp.join("docker-compose.yml"), "container_name: manager_container").unwrap();
        assert!(is_project_dir(&tmp));
        std::fs::remove_dir_all(&tmp).unwrap();
    }
}
