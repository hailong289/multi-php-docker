use tauri::image::Image;
use tauri::menu::{IsMenuItem, Menu, MenuItem, PredefinedMenuItem, Submenu};
use tauri::tray::TrayIconBuilder;
use tauri::{AppHandle, Wry};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons, MessageDialogKind};

use tauri_plugin_opener::OpenerExt;

use crate::{current_lang, docker, go_launcher, go_manager, project_dir, work_dir};

/// Native strings (tray, dialogs, notifications). The launcher page sends its language via `set_lang`.
pub fn tr(lang: &str, key: &str) -> &'static str {
    let en = lang == "en";
    match key {
        "manager" => if en { "Open Manager" } else { "Mở Manager" },
        "launcher" => if en { "Start screen" } else { "Màn hình khởi động" },
        "up" => if en { "Start stack" } else { "Khởi động stack" },
        "restart" => if en { "Restart stack" } else { "Khởi động lại stack" },
        "stop" => if en { "Stop stack…" } else { "Dừng stack…" },
        "pick" => if en { "Change folder…" } else { "Đổi thư mục…" },
        "quit" => if en { "Quit" } else { "Thoát" },
        "stop_title" => if en { "Stop stack" } else { "Dừng stack" },
        "stop_message" => if en {
            "Stop all Multi PHP containers (docker compose stop)? Containers are not removed."
        } else {
            "Dừng toàn bộ container của Multi PHP (docker compose stop)? Container không bị xoá."
        },
        "stop_ok" => if en { "Stop" } else { "Dừng" },
        "cancel" => if en { "Cancel" } else { "Huỷ" },
        "status_ok" => if en { "Manager is running" } else { "Manager đang chạy" },
        "status_wait" => if en { "Working…" } else { "Đang chạy lệnh…" },
        "status_off" => if en { "Manager is stopped" } else { "Manager đang tắt" },
        "action_up" => if en { "Start" } else { "Khởi động" },
        "action_build" => if en { "Build" } else { "Build" },
        "action_stop" => if en { "Stop stack" } else { "Dừng stack" },
        "action_restart" => if en { "Restart" } else { "Khởi động lại" },
        "done" => if en { "done" } else { "xong" },
        "sites" => if en { "Sites" } else { "Sites" },
        "no_sites" => if en { "No sites yet" } else { "Chưa có site" },
        "folder" => if en { "Open project folder" } else { "Mở thư mục dự án" },
        "container_stopped" => if en { "Container {name} stopped." } else { "Container {name} đã dừng." },
        "prune_title" => if en { "Clean up Docker" } else { "Dọn dẹp Docker" },
        "prune_message" => if en {
            "Remove stopped containers, unused networks, dangling images and build cache (docker system prune -f)? Volumes (database data) are kept. This cannot be undone."
        } else {
            "Xoá container đã dừng, network không dùng, image treo và build cache (docker system prune -f)? Volume (dữ liệu database) được giữ nguyên. Không hoàn tác được."
        },
        "prune_ok" => if en { "Clean up" } else { "Dọn dẹp" },
        "failed" => if en { "failed, open the app to see the log" } else { "lỗi, mở app để xem log" },
        _ => "",
    }
}

fn menu(app: &AppHandle, lang: &str) -> tauri::Result<Menu<Wry>> {
    let item = |id: &str| MenuItem::with_id(app, id, tr(lang, id), true, None::<&str>);
    // Menu ids "site:<url>" open that URL; URLs are built from env.json host names only (docker::sites).
    let sites = project_dir(app).map(|d| docker::sites(&d)).unwrap_or_default();
    let site_items = sites
        .iter()
        .map(|s| MenuItem::with_id(app, format!("site:{}", s.url), &s.name, true, None::<&str>))
        .collect::<tauri::Result<Vec<_>>>()?;
    let empty = MenuItem::with_id(app, "no_sites", tr(lang, "no_sites"), false, None::<&str>)?;
    let site_refs: Vec<&dyn IsMenuItem<Wry>> =
        if site_items.is_empty() { vec![&empty] } else { site_items.iter().map(|i| i as &dyn IsMenuItem<Wry>).collect() };
    let sites_menu = Submenu::with_items(app, tr(lang, "sites"), true, &site_refs)?;
    Menu::with_items(
        app,
        &[
            &item("manager")?,
            &item("launcher")?,
            &sites_menu,
            &PredefinedMenuItem::separator(app)?,
            &item("up")?,
            &item("restart")?,
            &item("stop")?,
            &PredefinedMenuItem::separator(app)?,
            &item("folder")?,
            &item("pick")?,
            &item("quit")?,
        ],
    )
}

pub fn set_lang(app: &AppHandle, lang: &str) -> tauri::Result<()> {
    if let Some(tray) = app.tray_by_id("main") {
        tray.set_menu(Some(menu(app, lang)?))?;
    }
    Ok(())
}

#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Status {
    Ok,
    Wait,
    Off,
}

/// The app icon with a status dot in the bottom-right corner (green / amber / grey).
fn status_icon(base: &Image, status: Status) -> Image<'static> {
    let (w, h) = (base.width() as i64, base.height() as i64);
    let mut rgba = base.rgba().to_vec();
    let color: [u8; 3] = match status {
        Status::Ok => [0x22, 0xc5, 0x5e],
        Status::Wait => [0xf5, 0x9e, 0x0b],
        Status::Off => [0x9c, 0xa3, 0xaf],
    };
    let r = w * 22 / 100;
    let (cx, cy) = (w - r - w / 32, h - r - h / 32);
    let ring = r + (w / 24).max(1);
    for y in (cy - ring).max(0)..(cy + ring).min(h) {
        for x in (cx - ring).max(0)..(cx + ring).min(w) {
            let d = (x - cx).pow(2) + (y - cy).pow(2);
            let px = if d <= r * r { [color[0], color[1], color[2], 255] } else if d <= ring * ring { [255, 255, 255, 255] } else { continue };
            let i = ((y * w + x) * 4) as usize;
            rgba[i..i + 4].copy_from_slice(&px);
        }
    }
    Image::new_owned(rgba, w as u32, h as u32)
}

pub fn set_status(app: &AppHandle, status: Status) {
    let (Some(tray), Some(base)) = (app.tray_by_id("main"), app.default_window_icon()) else { return };
    let key = match status {
        Status::Ok => "status_ok",
        Status::Wait => "status_wait",
        Status::Off => "status_off",
    };
    let _ = tray.set_icon(Some(status_icon(base, status)));
    let _ = tray.set_tooltip(Some(format!("Multi PHP · {}", tr(&current_lang(app), key))));
}

pub fn build(app: &AppHandle) -> tauri::Result<()> {
    let mut tray = TrayIconBuilder::with_id("main")
        .tooltip("Multi PHP")
        .menu(&menu(app, &current_lang(app))?)
        .show_menu_on_left_click(true)
        .on_menu_event(|app, event| match event.id().as_ref() {
            id if id.starts_with("site:") => {
                let _ = app.opener().open_url(&id["site:".len()..], None::<&str>);
            }
            "folder" => {
                if let Some(dir) = work_dir(app) {
                    let _ = app.opener().open_path(dir.display().to_string(), None::<&str>);
                }
            }
            "manager" => go_manager(app),
            "launcher" => go_launcher(app, "stay=1"),
            // Compose actions run on the launcher page so their output shows in its log.
            "up" => go_launcher(app, "action=up"),
            "restart" => go_launcher(app, "action=restart"),
            "stop" => {
                let handle = app.clone();
                let lang = current_lang(app);
                app.dialog()
                    .message(tr(&lang, "stop_message"))
                    .title(tr(&lang, "stop_title"))
                    .kind(MessageDialogKind::Warning)
                    .buttons(MessageDialogButtons::OkCancelCustom(tr(&lang, "stop_ok").into(), tr(&lang, "cancel").into()))
                    .show(move |ok| {
                        if ok {
                            go_launcher(&handle, "action=stop");
                        }
                    });
            }
            "pick" => go_launcher(app, "action=pick"),
            "quit" => app.exit(0),
            _ => {}
        });
    if let Some(icon) = app.default_window_icon() {
        tray = tray.icon(status_icon(icon, Status::Off));
    }
    tray.build(app)?;
    Ok(())
}
