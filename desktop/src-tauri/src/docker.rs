use std::io::{BufRead, BufReader, Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
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
        _ => None,
    }
}

/// Runs `docker compose …` in `dir`, calling `on_line` for every output line (stdout and stderr). Returns the exit code.
pub fn run_compose(dir: &Path, action: &str, on_line: impl Fn(String) + Send + Sync + 'static) -> Result<i32, String> {
    let args = compose_args(action).ok_or_else(|| format!("unknown action: {action}"))?;
    let mut cmd = docker_command(Some(dir)).ok_or("docker not found")?;
    let mut child = cmd
        .args(args)
        .env("COMPOSE_ANSI", "never")
        .env("COMPOSE_PROGRESS", "plain")
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| e.to_string())?;

    let on_line = std::sync::Arc::new(on_line);
    let stderr = child.stderr.take().unwrap();
    let err_line = on_line.clone();
    let err_thread = std::thread::spawn(move || {
        for line in BufReader::new(stderr).lines().map_while(Result::ok) {
            err_line(line);
        }
    });
    for line in BufReader::new(child.stdout.take().unwrap()).lines().map_while(Result::ok) {
        on_line(line);
    }
    let _ = err_thread.join();
    let status = child.wait().map_err(|e| e.to_string())?;
    Ok(status.code().unwrap_or(-1))
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
            return Err(format!("Không khởi động được {}.", app.display()));
        }
        // CLI not inside an app bundle (e.g. Homebrew): try the usual engines.
        for name in ["Docker", "OrbStack", "Rancher Desktop"] {
            if quiet_status(Command::new("open").args(["-g", "-j", "-a", name])) {
                return Ok(());
            }
        }
        return Err("Không tìm thấy Docker Desktop, OrbStack hay Rancher Desktop. Hãy khởi động Docker bằng tay.".into());
    }
    let result = if cfg!(windows) {
        Command::new(r"C:\Program Files\Docker\Docker\Docker Desktop.exe").spawn().map(|_| std::process::ExitStatus::default())
    } else {
        return Err("Hãy khởi động Docker bằng tay (systemctl start docker).".into());
    };
    match result {
        Ok(s) if s.success() => Ok(()),
        Ok(_) => Err("Không mở được Docker Desktop.".into()),
        Err(e) => Err(format!("Không mở được Docker Desktop: {e}")),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_fixed_actions() {
        assert!(compose_args("up").is_some());
        assert!(compose_args("stop").is_some());
        assert!(compose_args("down").is_none());
        assert!(compose_args("up; rm -rf /").is_none());
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
