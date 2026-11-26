use tauri::menu::{Menu, MenuItem, PredefinedMenuItem};
use tauri::tray::TrayIconBuilder;
use tauri::AppHandle;
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons, MessageDialogKind};

use crate::{go_launcher, go_manager};

pub fn build(app: &AppHandle) -> tauri::Result<()> {
    let menu = Menu::with_items(
        app,
        &[
            &MenuItem::with_id(app, "manager", "Mở Manager", true, None::<&str>)?,
            &MenuItem::with_id(app, "launcher", "Màn hình khởi động", true, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &MenuItem::with_id(app, "up", "Khởi động stack", true, None::<&str>)?,
            &MenuItem::with_id(app, "stop", "Dừng stack…", true, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &MenuItem::with_id(app, "pick", "Đổi thư mục dự án…", true, None::<&str>)?,
            &MenuItem::with_id(app, "quit", "Thoát", true, None::<&str>)?,
        ],
    )?;

    let mut tray = TrayIconBuilder::with_id("main")
        .tooltip("Multi PHP")
        .menu(&menu)
        .show_menu_on_left_click(true)
        .on_menu_event(|app, event| match event.id().as_ref() {
            "manager" => go_manager(app),
            "launcher" => go_launcher(app, "stay=1"),
            // Compose actions run on the launcher page so their output shows in its log.
            "up" => go_launcher(app, "action=up"),
            "stop" => {
                let handle = app.clone();
                app.dialog()
                    .message("Dừng toàn bộ container của Multi PHP (docker compose stop)? Container không bị xoá.")
                    .title("Dừng stack")
                    .kind(MessageDialogKind::Warning)
                    .buttons(MessageDialogButtons::OkCancelCustom("Dừng".into(), "Huỷ".into()))
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
        tray = tray.icon(icon.clone());
    }
    tray.build(app)?;
    Ok(())
}
