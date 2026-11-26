// Hide the console window on Windows release builds.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod docker;
mod tray;

use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Mutex;
use std::time::Duration;

use tauri::{AppHandle, Emitter, Manager, RunEvent, Url, WebviewUrl, WebviewWindow, WebviewWindowBuilder, WindowEvent};
use tauri_plugin_dialog::DialogExt;
use tauri_plugin_opener::OpenerExt;

pub const MANAGER_URL: &str = "http://127.0.0.1:8080/server-manage/";

#[derive(Default)]
struct AppState {
    launcher_url: Mutex<Option<Url>>,
    busy: AtomicBool,
}

// ---------- project dir (config.json in the app config dir) ----------

fn config_file(app: &AppHandle) -> Option<PathBuf> {
    app.path().app_config_dir().ok().map(|d| d.join("config.json"))
}

fn project_dir(app: &AppHandle) -> Option<PathBuf> {
    let text = std::fs::read_to_string(config_file(app)?).ok()?;
    let json: serde_json::Value = serde_json::from_str(&text).ok()?;
    let dir = PathBuf::from(json.get("project_dir")?.as_str()?);
    docker::is_project_dir(&dir).then_some(dir)
}

fn save_project_dir(app: &AppHandle, dir: &std::path::Path) -> Result<(), String> {
    let file = config_file(app).ok_or("no config dir")?;
    std::fs::create_dir_all(file.parent().unwrap()).map_err(|e| e.to_string())?;
    let json = serde_json::json!({ "project_dir": dir });
    std::fs::write(file, serde_json::to_string_pretty(&json).unwrap()).map_err(|e| e.to_string())
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
    if let Some(w) = main_window(app) {
        let _ = w.navigate(Url::parse(MANAGER_URL).unwrap());
    }
    show_main(app);
}

// ---------- commands (launcher page only, see capabilities/launcher.json) ----------

#[derive(serde::Serialize)]
struct Status {
    project_dir: Option<String>,
    docker_found: bool,
    docker_running: bool,
    manager: docker::Port,
    busy: bool,
}

#[tauri::command]
async fn status(app: AppHandle) -> Status {
    let state = app.state::<AppState>();
    let busy = state.busy.load(Ordering::SeqCst);
    let project_dir = project_dir(&app).map(|d| d.display().to_string());
    tauri::async_runtime::spawn_blocking(move || Status {
        project_dir,
        docker_found: docker::docker_bin().is_some(),
        docker_running: docker::docker_running(),
        manager: docker::manager_port(),
        busy,
    })
    .await
    .unwrap()
}

#[tauri::command]
async fn pick_project_dir(app: AppHandle) -> Result<Option<String>, String> {
    let Some(picked) = app.dialog().file().set_title("Chọn thư mục Multi PHP").blocking_pick_folder() else {
        return Ok(None);
    };
    let dir = picked.into_path().map_err(|e| e.to_string())?;
    if !docker::is_project_dir(&dir) {
        return Err(format!("{} không có docker-compose.yml của Multi PHP.", dir.display()));
    }
    save_project_dir(&app, &dir)?;
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
    let dir = project_dir(&app).ok_or("Chưa chọn thư mục dự án.")?;
    let state = app.state::<AppState>();
    if state.busy.swap(true, Ordering::SeqCst) {
        return Err("Đang chạy một lệnh khác.".into());
    }
    let emitter = app.clone();
    let _ = app.emit("compose-log", format!("$ docker {}", docker::compose_args(&action).unwrap().join(" ")));
    let result = tauri::async_runtime::spawn_blocking(move || {
        docker::run_compose(&dir, &action, move |line| {
            let _ = emitter.emit("compose-log", line);
        })
    })
    .await
    .unwrap();
    state.busy.store(false, Ordering::SeqCst);
    result
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

/// While the Manager is shown, go back to the launcher once it stops answering (e.g. the stack was stopped).
fn watch_manager(app: AppHandle) {
    std::thread::spawn(move || {
        let mut misses = 0;
        loop {
            std::thread::sleep(Duration::from_secs(5));
            let on_manager = main_window(&app)
                .and_then(|w| w.url().ok())
                .is_some_and(|u| is_manager_url(&u));
            if !on_manager {
                misses = 0;
                continue;
            }
            misses = if docker::manager_port() == docker::Port::Manager { 0 } else { misses + 1 };
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
        .manage(AppState::default())
        .invoke_handler(tauri::generate_handler![status, pick_project_dir, open_docker, compose, open_manager])
        .setup(|app| {
            let handle = app.handle().clone();
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
            // Closing the window keeps the app in the menu bar / tray; "Thoát" quits.
            if let WindowEvent::CloseRequested { api, .. } = event {
                api.prevent_close();
                let _ = window.hide();
            }
        })
        .build(tauri::generate_context!())
        .expect("error while building app")
        .run(|app, event| {
            #[cfg(target_os = "macos")]
            if let RunEvent::Reopen { .. } = event {
                show_main(app);
            }
            let _ = (app, event);
        });
}
