// Hide the console window on Windows release builds.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod docker;
mod stack;
mod tray;

use std::path::PathBuf;
use std::process::Child;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Mutex;
use std::time::Duration;

use tauri::{AppHandle, Emitter, Manager, RunEvent, Url, WebviewUrl, WebviewWindow, WebviewWindowBuilder, WindowEvent};
use tauri_plugin_autostart::{ManagerExt as _, MacosLauncher};
use tauri_plugin_dialog::DialogExt;
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_opener::OpenerExt;
use tauri_plugin_updater::UpdaterExt;

pub const MANAGER_URL: &str = "http://127.0.0.1:8080/server-manage/";

#[derive(Default)]
struct AppState {
    launcher_url: Mutex<Option<Url>>,
    busy: AtomicBool,
    lang: Mutex<String>,
    /// `docker compose logs -f` currently streamed to the launcher, if any.
    follower: Mutex<Option<Child>>,
}

/// "vi" or "en", as last chosen on the launcher page.
pub fn current_lang(app: &AppHandle) -> String {
    app.state::<AppState>().lang.lock().unwrap().clone()
}

// ---------- project / source dir (config.json in the app config dir) ----------
//
// Two modes: {"source_dir": …} runs the stack bundled with the app (~/.multi-php/stack) on the user's source
// folder; {"project_dir": …} runs an existing multi-php-docker checkout.

fn config_file(app: &AppHandle) -> Option<PathBuf> {
    app.path().app_config_dir().ok().map(|d| d.join("config.json"))
}

fn config_path(app: &AppHandle, key: &str) -> Option<PathBuf> {
    let text = std::fs::read_to_string(config_file(app)?).ok()?;
    let json: serde_json::Value = serde_json::from_str(&text).ok()?;
    Some(PathBuf::from(json.get(key)?.as_str()?))
}

/// The user's source folder in bundled mode.
pub fn source_dir(app: &AppHandle) -> Option<PathBuf> {
    config_path(app, "source_dir").filter(|d| d.is_dir())
}

/// Where `docker compose` runs: the installed bundled stack, or the user's checkout.
pub fn project_dir(app: &AppHandle) -> Option<PathBuf> {
    let dir = if source_dir(app).is_some() { stack::stack_dir()? } else { config_path(app, "project_dir")? };
    docker::is_project_dir(&dir).then_some(dir)
}

/// The folder the user works in (opened by "Open folder" / VS Code).
pub fn work_dir(app: &AppHandle) -> Option<PathBuf> {
    source_dir(app).or_else(|| project_dir(app))
}

fn save_config(app: &AppHandle, key: &str, dir: &std::path::Path) -> Result<(), String> {
    let file = config_file(app).ok_or("no config dir")?;
    std::fs::create_dir_all(file.parent().unwrap()).map_err(|e| e.to_string())?;
    let json = serde_json::json!({ key: dir });
    std::fs::write(file, serde_json::to_string_pretty(&json).unwrap()).map_err(|e| e.to_string())
}

/// Installs or updates the bundled stack and points it at `source`.
fn install_stack(app: &AppHandle, source: &std::path::Path) -> Result<(), String> {
    let bundled = app.path().resource_dir().map_err(|e| e.to_string())?.join("stack");
    let target = stack::stack_dir().ok_or("no home dir")?;
    stack::install(&bundled, &target).map_err(|e| format!("stack_install_failed|{e}"))?;
    // Fixed project name: the stack dir's own name ("stack") would be a poor compose project name.
    stack::set_env(&target, &[("SOURCE_DIR", &stack::env_path(source)), ("COMPOSE_PROJECT_NAME", "multi-php")])
        .map_err(|e| format!("stack_install_failed|{e}"))
}

/// Switching between the bundled stack and a checkout while one runs would clash on container names.
fn ensure_switch_allowed(app: &AppHandle, to_bundled: bool) -> Result<(), String> {
    let bundled_now = source_dir(app).is_some();
    let has_config = project_dir(app).is_some();
    if has_config && bundled_now != to_bundled && docker::manager_port() == docker::Port::Manager {
        return Err("stop_stack_first".into());
    }
    Ok(())
}

// ---------- navigation ----------

fn is_manager_url(url: &Url) -> bool {
    url.scheme() == "http" && url.host_str() == Some("127.0.0.1") && url.port() == Some(8080)
}

fn is_launcher_url(url: &Url) -> bool {
    url.scheme() == "tauri" || url.host_str() == Some("tauri.localhost")
}

pub fn main_window(app: &AppHandle) -> Option<WebviewWindow> {
    app.get_webview_window("main")
}

pub fn show_main(app: &AppHandle) {
    if let Some(w) = main_window(app) {
        let _ = w.show();
        let _ = w.unminimize();
        let _ = w.set_focus();
    }
}

/// Back to the launcher page. `query` carries a follow-up for it, e.g. "action=up" or "stay=1".
pub fn go_launcher(app: &AppHandle, query: &str) {
    let state = app.state::<AppState>();
    let Some(mut url) = state.launcher_url.lock().unwrap().clone() else { return };
    url.set_query(Some(query));
    if let Some(w) = main_window(app) {
        let _ = w.navigate(url);
    }
    show_main(app);
}

pub fn go_manager(app: &AppHandle) {
    let mut url = Url::parse(MANAGER_URL).unwrap();
    // The Manager reads ?lang= once, so it opens in the language chosen on the launcher.
    let lang = current_lang(app);
    if !lang.is_empty() {
        url.set_query(Some(&format!("lang={lang}")));
    }
    if let Some(w) = main_window(app) {
        let _ = w.navigate(url);
    }
    show_main(app);
}

// ---------- commands (launcher page only, see capabilities/launcher.json) ----------

#[derive(serde::Serialize)]
struct Status {
    project_dir: Option<String>,
    source_dir: Option<String>,
    docker_found: bool,
    docker_running: bool,
    manager: docker::Port,
    busy: bool,
    port_owner: Option<String>,
    services: Vec<docker::Service>,
}

#[tauri::command]
async fn status(app: AppHandle) -> Status {
    let state = app.state::<AppState>();
    let busy = state.busy.load(Ordering::SeqCst);
    let dir = project_dir(&app);
    let source = source_dir(&app).map(|d| d.display().to_string());
    tauri::async_runtime::spawn_blocking(move || {
        let docker_running = docker::docker_running();
        let manager = docker::manager_port();
        Status {
            project_dir: dir.as_ref().map(|d| d.display().to_string()),
            source_dir: source,
            docker_found: docker::docker_bin().is_some(),
            docker_running,
            manager,
            busy,
            port_owner: (manager == docker::Port::Other).then(docker::port_owner).flatten(),
            services: dir.filter(|_| docker_running).map(|d| docker::services(&d)).unwrap_or_default(),
        }
    })
    .await
    .unwrap()
}

#[tauri::command]
async fn pick_project_dir(app: AppHandle) -> Result<Option<String>, String> {
    let Some(picked) = app.dialog().file().set_title("Multi PHP").blocking_pick_folder() else {
        return Ok(None);
    };
    let dir = picked.into_path().map_err(|e| e.to_string())?;
    if !docker::is_project_dir(&dir) {
        return Err(format!("not_project_dir|{}", dir.display()));
    }
    let check = app.clone();
    tauri::async_runtime::spawn_blocking(move || ensure_switch_allowed(&check, false)).await.unwrap()?;
    save_config(&app, "project_dir", &dir)?;
    Ok(Some(dir.display().to_string()))
}

/// Bundled mode: the user picks only the folder with their PHP projects.
#[tauri::command]
async fn pick_source_dir(app: AppHandle) -> Result<Option<String>, String> {
    let Some(picked) = app.dialog().file().set_title("Multi PHP").blocking_pick_folder() else {
        return Ok(None);
    };
    let dir = picked.into_path().map_err(|e| e.to_string())?;
    if stack::stack_dir().is_some_and(|s| dir.starts_with(s.parent().unwrap())) {
        return Err(format!("source_in_stack|{}", dir.display()));
    }
    let handle = app.clone();
    let source = dir.clone();
    tauri::async_runtime::spawn_blocking(move || {
        ensure_switch_allowed(&handle, true)?;
        install_stack(&handle, &source)
    })
    .await
    .unwrap()?;
    save_config(&app, "source_dir", &dir)?;
    Ok(Some(dir.display().to_string()))
}

#[tauri::command]
async fn open_docker() -> Result<(), String> {
    tauri::async_runtime::spawn_blocking(docker::open_docker_desktop).await.unwrap()
}

/// Runs one fixed compose action; output lines go to the "compose-log" event. Returns the exit code.
#[tauri::command]
async fn compose(app: AppHandle, action: String) -> Result<i32, String> {
    docker::compose_args(&action).ok_or("unknown action")?;
    let dir = project_dir(&app).ok_or("no_project_dir")?;
    let state = app.state::<AppState>();
    if state.busy.swap(true, Ordering::SeqCst) {
        return Err("busy".into());
    }
    let emitter = app.clone();
    let run_action = action.clone();
    let _ = app.emit("compose-log", format!("$ docker {}", docker::compose_args(&action).unwrap().join(" ")));
    let result = tauri::async_runtime::spawn_blocking(move || {
        docker::run_compose(&dir, &run_action, move |line| {
            let _ = emitter.emit("compose-log", line);
        })
    })
    .await
    .unwrap();
    state.busy.store(false, Ordering::SeqCst);
    notify_done(&app, &action, &result);
    result
}

/// Tells the user a compose run ended when they are not looking at the app (build/start take minutes).
fn notify_done(app: &AppHandle, action: &str, result: &Result<i32, String>) {
    let focused = main_window(app).is_some_and(|w| w.is_visible().unwrap_or(false) && w.is_focused().unwrap_or(false));
    let ok = matches!(result, Ok(0));
    // A successful build is followed by "up"; only that one is worth a notification.
    if focused || (ok && action == "build") {
        return;
    }
    let lang = current_lang(app);
    let body = format!("{} {}", tray::tr(&lang, &format!("action_{action}")), tray::tr(&lang, if ok { "done" } else { "failed" }));
    let _ = app.notification().builder().title("Multi PHP").body(body).show();
}

/// Marks the app busy for the duration of `f` (one docker command at a time). Errors with "busy" otherwise.
async fn exclusive<T: Send + 'static>(app: &AppHandle, f: impl FnOnce() -> Result<T, String> + Send + 'static) -> Result<T, String> {
    let state = app.state::<AppState>();
    if state.busy.swap(true, Ordering::SeqCst) {
        return Err("busy".into());
    }
    let result = tauri::async_runtime::spawn_blocking(f).await.unwrap();
    state.busy.store(false, Ordering::SeqCst);
    result
}

fn log_emitter(app: &AppHandle) -> impl Fn(String) + Send + Sync + 'static {
    let app = app.clone();
    move |line| {
        let _ = app.emit("compose-log", line);
    }
}

/// `docker compose start|restart|stop <service>` for one container of the stack.
/// A container that was running went down without the app asking (crash, OOM, stopped elsewhere).
fn notify_stopped(app: &AppHandle, service: &str) {
    let body = tray::tr(&current_lang(app), "container_stopped").replace("{name}", service);
    let _ = app.notification().builder().title("Multi PHP").body(body).show();
}

#[tauri::command]
async fn service_action(app: AppHandle, service: String, action: String) -> Result<i32, String> {
    let verb = docker::service_args(&action).ok_or("unknown action")?;
    let dir = project_dir(&app).ok_or("no_project_dir")?;
    let emit = log_emitter(&app);
    exclusive(&app, move || {
        docker::check_service(&dir, &service)?;
        emit(format!("$ docker compose {verb} {service}"));
        docker::run_streaming(Some(&dir), &["compose", verb, &service], emit)
    })
    .await
}

fn stop_follower(app: &AppHandle) {
    if let Some(mut child) = app.state::<AppState>().follower.lock().unwrap().take() {
        let _ = child.kill();
        let _ = child.wait();
    }
}

/// Streams one service's log to the "follow-log" event until `stop_logs` (or another follow).
#[tauri::command]
async fn follow_logs(app: AppHandle, service: String) -> Result<(), String> {
    let dir = project_dir(&app).ok_or("no_project_dir")?;
    stop_follower(&app);
    let emitter = app.clone();
    let child = tauri::async_runtime::spawn_blocking(move || {
        docker::follow_logs(&dir, &service, move |line| {
            let _ = emitter.emit("follow-log", line);
        })
    })
    .await
    .unwrap()?;
    *app.state::<AppState>().follower.lock().unwrap() = Some(child);
    Ok(())
}

#[tauri::command]
fn stop_logs(app: AppHandle) {
    stop_follower(&app);
}

#[tauri::command]
async fn stats(containers: Vec<String>) -> Vec<docker::Stat> {
    tauri::async_runtime::spawn_blocking(move || docker::stats(&containers)).await.unwrap()
}

#[tauri::command]
async fn open_terminal(app: AppHandle, service: String) -> Result<(), String> {
    let dir = project_dir(&app).ok_or("no_project_dir")?;
    tauri::async_runtime::spawn_blocking(move || docker::open_terminal(&dir, &service)).await.unwrap()
}

/// "folder" opens the project in Finder/Explorer, "code" in VS Code.
#[tauri::command]
async fn open_project(app: AppHandle, with: String) -> Result<(), String> {
    let dir = work_dir(&app).ok_or("no_project_dir")?;
    if with == "code" {
        return tauri::async_runtime::spawn_blocking(move || docker::open_vscode(&dir)).await.unwrap();
    }
    app.opener().open_path(dir.display().to_string(), None::<&str>).map_err(|e| e.to_string())
}

/// `docker system prune -f` after a native confirmation. Volumes (database data) are never touched.
/// Returns None when the user cancels.
#[tauri::command]
async fn prune(app: AppHandle) -> Result<Option<i32>, String> {
    let lang = current_lang(&app);
    let dialog = app.clone();
    let confirmed = tauri::async_runtime::spawn_blocking(move || {
        dialog
            .dialog()
            .message(tray::tr(&lang, "prune_message"))
            .title(tray::tr(&lang, "prune_title"))
            .kind(tauri_plugin_dialog::MessageDialogKind::Warning)
            .buttons(tauri_plugin_dialog::MessageDialogButtons::OkCancelCustom(
                tray::tr(&lang, "prune_ok").into(),
                tray::tr(&lang, "cancel").into(),
            ))
            .blocking_show()
    })
    .await
    .unwrap();
    if !confirmed {
        return Ok(None);
    }
    let emit = log_emitter(&app);
    exclusive(&app, move || {
        emit("$ docker system prune -f".into());
        docker::run_streaming(None, &["system", "prune", "-f"], emit)
    })
    .await
    .map(Some)
}

#[tauri::command]
fn set_lang(app: AppHandle, lang: String) -> Result<(), String> {
    let lang = if lang == "en" { "en" } else { "vi" };
    *app.state::<AppState>().lang.lock().unwrap() = lang.into();
    tray::set_lang(&app, lang).map_err(|e| e.to_string())
}

#[tauri::command]
fn autostart_enabled(app: AppHandle) -> bool {
    app.autolaunch().is_enabled().unwrap_or(false)
}

#[tauri::command]
fn set_autostart(app: AppHandle, on: bool) -> Result<(), String> {
    let launch = app.autolaunch();
    if on { launch.enable() } else { launch.disable() }.map_err(|e| e.to_string())
}

/// Newer version on GitHub Releases, if any. Errors (offline, no release yet) just mean "no update".
#[tauri::command]
async fn check_update(app: AppHandle) -> Option<String> {
    app.updater().ok()?.check().await.ok()?.map(|u| u.version)
}

/// Downloads and installs the update, then restarts the app.
#[tauri::command]
async fn install_update(app: AppHandle) -> Result<(), String> {
    let update = app.updater().map_err(|e| e.to_string())?.check().await.map_err(|e| e.to_string())?;
    let Some(update) = update else { return Ok(()) };
    update.download_and_install(|_, _| {}, || {}).await.map_err(|e| e.to_string())?;
    app.restart()
}

#[tauri::command]
fn open_manager(app: AppHandle) {
    go_manager(&app);
}

// ---------- app ----------

const OPEN_BLANK_LINKS: &str = r#"
document.addEventListener('click', (event) => {
  const link = event.target instanceof Element && event.target.closest('a[href][target]');
  if (!link || event.defaultPrevented || ['', '_self', '_parent', '_top'].includes(link.target)) return;
  event.preventDefault();
  window.open(link.href, '_blank');
}, true);
"#;

fn build_window(app: &AppHandle) -> tauri::Result<WebviewWindow> {
    let opener = app.clone();
    let opener_new = app.clone();
    WebviewWindowBuilder::new(app, "main", WebviewUrl::App("index.html".into()))
        .title("Multi PHP")
        // Started at login: stay in the menu bar / tray until the user opens the window.
        .visible(!std::env::args().any(|a| a == HIDDEN_ARG))
        .inner_size(1280.0, 840.0)
        .min_inner_size(720.0, 520.0)
        // Only the launcher and the Manager load inside the app; anything else (site links,
        // multi-php-hosts: for the hosts helper) goes to the OS.
        .on_navigation(move |url| {
            if is_launcher_url(url) || is_manager_url(url) || url.scheme() == "about" {
                return true;
            }
            let _ = opener.opener().open_url(url.as_str(), None::<&str>);
            false
        })
        // WKWebView drops clicks on links that open another window (site links, multi-php-hosts:); route them
        // through window.open, which reaches on_new_window below.
        .initialization_script(OPEN_BLANK_LINKS)
        .on_new_window(move |url, _features| {
            let _ = opener_new.opener().open_url(url.as_str(), None::<&str>);
            tauri::webview::NewWindowResponse::Deny
        })
        .build()
}

const HIDDEN_ARG: &str = "--hidden";

/// Keeps the tray dot in sync, and while the Manager is shown, goes back to the launcher once it stops
/// answering (e.g. the stack was stopped).
fn watch_manager(app: AppHandle) {
    std::thread::spawn(move || {
        let mut misses = 0;
        let mut shown = None;
        let mut running: Option<Vec<String>> = None;
        let mut sites = Vec::new();
        loop {
            let dir = project_dir(&app);
            let now_sites = dir.as_deref().map(docker::sites).unwrap_or_default();
            if now_sites != sites {
                sites = now_sites;
                let _ = tray::set_lang(&app, &current_lang(&app));
            }
            if app.state::<AppState>().busy.load(Ordering::SeqCst) {
                // Our own stop/restart is not news; take a fresh baseline afterwards.
                running = None;
            } else if let Some(dir) = dir.as_deref() {
                let services = docker::services(dir);
                let now: Vec<String> = services.iter().filter(|s| s.state == "running").map(|s| s.name.clone()).collect();
                if let Some(before) = &running {
                    for svc in services.iter().filter(|s| s.state != "running" && before.contains(&s.name)) {
                        notify_stopped(&app, &svc.name);
                    }
                }
                running = Some(now);
            }
            let port = docker::manager_port();
            let status = if port == docker::Port::Manager {
                tray::Status::Ok
            } else if app.state::<AppState>().busy.load(Ordering::SeqCst) {
                tray::Status::Wait
            } else {
                tray::Status::Off
            };
            if shown != Some(status) {
                shown = Some(status);
                tray::set_status(&app, status);
            }
            std::thread::sleep(Duration::from_secs(5));
            let on_manager = main_window(&app)
                .and_then(|w| w.url().ok())
                .is_some_and(|u| is_manager_url(&u));
            if !on_manager {
                misses = 0;
                continue;
            }
            misses = if port == docker::Port::Manager { 0 } else { misses + 1 };
            if misses >= 2 {
                misses = 0;
                let handle = app.clone();
                let _ = app.run_on_main_thread(move || go_launcher(&handle, "stay=1"));
            }
        }
    });
}

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(tauri_plugin_autostart::init(MacosLauncher::LaunchAgent, Some(vec![HIDDEN_ARG])))
        .manage(AppState::default())
        .invoke_handler(tauri::generate_handler![
            status,
            pick_project_dir,
            open_docker,
            compose,
            open_manager,
            service_action,
            follow_logs,
            stop_logs,
            stats,
            open_terminal,
            open_project,
            prune,
            set_lang,
            autostart_enabled,
            set_autostart,
            check_update,
            install_update,
            pick_source_dir
        ])
        .setup(|app| {
            let handle = app.handle().clone();
            // A new app version may bundle a newer stack; install it before anything runs compose.
            if let Some(source) = source_dir(&handle) {
                if let Err(e) = install_stack(&handle, &source) {
                    eprintln!("stack install: {e}");
                }
            }
            let window = build_window(&handle)?;
            *app.state::<AppState>().launcher_url.lock().unwrap() = window.url().ok().map(|mut u| {
                u.set_query(None);
                u
            });
            tray::build(&handle)?;
            watch_manager(handle);
            Ok(())
        })
        .on_window_event(|window, event| {
            // Closing the window keeps the app in the menu bar / tray; "Quit" quits.
            if let WindowEvent::CloseRequested { api, .. } = event {
                api.prevent_close();
                let _ = window.hide();
            }
        })
        .build(tauri::generate_context!())
        .expect("error while building app")
        .run(|app, event| {
            if let RunEvent::Exit = event {
                stop_follower(app);
            }
            #[cfg(target_os = "macos")]
            if let RunEvent::Reopen { .. } = event {
                show_main(app);
            }
            let _ = (app, event);
        });
}
