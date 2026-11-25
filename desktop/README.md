# Multi PHP Desktop (Tauri)

> Trạng thái: **thiết kế**, chưa có code. Tài liệu này chốt phạm vi và cách làm trước khi bắt đầu.

App desktop mở Server Manager trong cửa sổ riêng, có icon trên Dock/taskbar và thanh menu. App **không tự chạy Docker**: mọi thao tác (bật Docker, khởi động, dừng stack) đều do người dùng bấm nút.

Manager vẫn là backend PHP trong `manager_container` (`http://127.0.0.1:8080/server-manage/`). App chỉ là lớp vỏ: kiểm tra trạng thái, chạy vài lệnh `docker compose` cố định khi người dùng yêu cầu, rồi hiển thị Manager.

## Phạm vi

**Có trong bản đầu**

- Màn hình khởi động: trạng thái Docker và Manager, các nút thao tác, log lệnh đang chạy.
- Hiển thị Manager trong cửa sổ app khi cổng 8080 sẵn sàng.
- Icon trên thanh menu (macOS) / khay hệ thống (Windows).
- Chọn và nhớ thư mục dự án (thư mục chứa `docker-compose.yml`).
- Build cho macOS. Windows dùng cùng code, build sau.

**Không làm (bản đầu)**

- Tự bật Docker hoặc tự khởi động stack khi mở app.
- Tự khởi động app cùng hệ điều hành.
- Tự cập nhật app.
- Ký số / notarize để phát hành cho người khác.
- Bật/tắt từng service (Nginx, PHP, MySQL, …): việc này đã có trong Manager.

## Màn hình

### 1. Lần đầu mở app: chọn thư mục dự án

```
┌──────────────────────────────────────────────┐
│ Chọn thư mục Multi PHP                        │
│ Thư mục chứa docker-compose.yml của repo.     │
│                                               │
│ [ /Users/posapp/web                ] [Chọn…]  │
│                                [ Tiếp tục ]   │
└──────────────────────────────────────────────┘
```

- App kiểm tra thư mục có `docker-compose.yml` và service `manager` mới cho tiếp tục.
- Đổi lại được qua menu **Đổi thư mục dự án…**.

### 2. Màn hình khởi động (Manager chưa chạy)

```
┌──────────────────────────────────────────────┐
│ Multi PHP                                     │
│                                               │
│ ● Docker        Đang tắt     [Mở Docker]      │
│ ○ Manager       Chưa chạy    [Khởi động]      │
│                                               │
│ ┌──────────────────────────────────────────┐ │
│ │ $ docker compose up -d                    │ │
│ │ ✔ Container env-init      Exited          │ │
│ │ ✔ Container manager_container  Started    │ │
│ └──────────────────────────────────────────┘ │
│                            [ Mở Manager ]     │
└──────────────────────────────────────────────┘
```

| Nút | Hiện khi | Lệnh |
| --- | --- | --- |
| **Mở Docker** | Docker chưa chạy | macOS: `open -a Docker`; Windows: chạy `Docker Desktop.exe` |
| **Khởi động** | Docker chạy, Manager chưa chạy | `docker compose up -d` (đúng bước trong README: Manager + PHP Controller + `env-init`) |
| **Build lại** | Lần đầu, hoặc `up` báo thiếu image | `docker compose build` rồi `docker compose up -d` |
| **Thử lại** | Lệnh lỗi | Kiểm tra lại trạng thái |
| **Mở Manager** | Cổng 8080 trả lời | Chuyển cửa sổ sang Manager |

- Trạng thái tự cập nhật mỗi 3 giây khi đang ở màn hình này. App chỉ kiểm tra, không tự chạy lệnh.
- Ô log hiện output trực tiếp (stdout + stderr) của lệnh đang chạy. Trong lúc lệnh chạy, các nút khác bị khoá.
- Lệnh lỗi: giữ nguyên log, hiện mã lỗi và nút **Thử lại**.

### 3. Manager

Khi Manager sẵn sàng (và người dùng bấm **Mở Manager**, hoặc app mở lúc Manager đã chạy sẵn), cửa sổ tải `http://127.0.0.1:8080/server-manage/`.

- Link mở tab mới (`target="_blank"`, ví dụ domain site) mở bằng trình duyệt mặc định.
- Link `multi-php-hosts:…` (ghi hosts) chuyển cho hệ điều hành, để `MultiPhpHosts.app` / helper Windows xử lý như hiện tại.
- Mất kết nối tới 8080 (ví dụ stack bị dừng): quay về màn hình khởi động.

### 4. Menu trên thanh menu / khay

```
Multi PHP
├─ Mở Manager
├─ Màn hình khởi động
├─ ─────────
├─ Khởi động stack        (docker compose up -d)
├─ Dừng stack…            (docker compose stop, hỏi xác nhận)
├─ ─────────
├─ Đổi thư mục dự án…
└─ Thoát
```

- **Dừng stack** dùng `docker compose stop` (không xoá container/network), luôn hỏi xác nhận.
- **Thoát** chỉ đóng app, không dừng Docker hay container.
- Đóng cửa sổ: app vẫn ở trên thanh menu; **Thoát** mới tắt hẳn.

## Kiến trúc

```
desktop/
├─ README.md                 tài liệu này
├─ package.json              script: tauri dev / tauri build
├─ src/                      màn hình khởi động (HTML/JS tĩnh, không framework)
│  ├─ index.html
│  ├─ main.js
│  └─ style.css
└─ src-tauri/
   ├─ tauri.conf.json
   ├─ capabilities/          quyền IPC theo cửa sổ
   ├─ icons/
   └─ src/
      ├─ main.rs
      ├─ docker.rs           kiểm tra Docker, chạy lệnh compose
      └─ tray.rs             menu thanh menu / khay
```

- **Tauri v2.** Plugin: `dialog` (chọn thư mục, hỏi xác nhận), `store` (lưu thư mục dự án), `opener` (mở link ngoài và `multi-php-hosts:`).
- **Màn hình khởi động** là trang tĩnh đóng gói trong app, gọi Rust qua `invoke`. Không dùng Vue/PrimeVue để app nhẹ và không phụ thuộc frontend Manager.
- **Lệnh Rust** (danh sách cố định):

| Lệnh | Việc |
| --- | --- |
| `get_project_dir` / `set_project_dir(path)` | Đọc/ghi thư mục dự án (kiểm tra có `docker-compose.yml`) |
| `status()` | `{ docker: bool, manager: bool }` (`docker info` + kết nối HTTP tới `127.0.0.1:8080`) |
| `open_docker()` | Mở Docker Desktop |
| `compose(action)` | `action` ∈ `up` \| `build` \| `stop`; chạy trong thư mục dự án, đẩy từng dòng output qua event `compose-log`, trả về mã thoát |

## An toàn

- **Webview không chạy được lệnh tuỳ ý.** Rust chỉ nhận `action` trong danh sách trên, tự ghép tham số; không có lệnh shell nào nhận chuỗi từ webview.
- **Trang Manager (`http://127.0.0.1:8080`) không có quyền IPC.** Capability chỉ cấp cho trang khởi động đóng gói trong app. Manager là trang web từ xa, nếu có quyền `invoke` thì bất kỳ lỗi XSS nào trong Manager cũng chạy được lệnh trên máy.
- Thư mục dự án phải chứa `docker-compose.yml`; mọi lệnh chạy với `current_dir` là thư mục đó.
- Lệnh thay đổi trạng thái (`stop`) luôn hỏi xác nhận.

## Lưu ý kỹ thuật

- **PATH trên macOS:** app mở từ Finder không có PATH của shell, nên `docker` ở `/usr/local/bin` hoặc `/opt/homebrew/bin` có thể không tìm thấy. App tìm `docker` theo thứ tự: PATH hiện tại → `/usr/local/bin/docker` → `/opt/homebrew/bin/docker` → `~/.docker/bin/docker` (macOS) / `C:\Program Files\Docker\Docker\resources\bin\docker.exe` (Windows). Không tìm thấy thì báo "Chưa cài Docker" kèm link tải.
- **Docker vừa mở:** `docker info` có thể lỗi vài chục giây trong lúc Docker Desktop khởi động; màn hình hiện "Docker đang khởi động…" thay vì báo lỗi.
- **Manager chạy nhưng chưa trả lời:** sau `up -d`, PHP server trong container cần vài giây; trạng thái Manager chỉ "sẵn sàng" khi HTTP trả về.
- **Cổng 8080 bị chiếm** bởi app khác: kiểm tra HTTP thấy phản hồi không phải Manager → báo cổng bị chiếm, không mở trang đó.

## Build và chạy

Yêu cầu: Node.js (đã có), Rust (`rustup`), Xcode Command Line Tools trên macOS.

```bash
# cài Rust một lần
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh

cd desktop
npm install
npm run tauri dev      # chạy thử
npm run tauri build    # ra .app và .dmg trong src-tauri/target/release/bundle/
```

Windows: build trên máy Windows (cần Rust + Visual Studio Build Tools + WebView2), ra `.msi`/`.exe`.

## Kiểm tra trước khi coi là xong

- [ ] Docker tắt → nút **Mở Docker** mở được Docker Desktop, trạng thái tự chuyển khi Docker sẵn sàng.
- [ ] Manager dừng → **Khởi động** chạy `docker compose up -d`, log hiện đúng, xong thì **Mở Manager** sáng.
- [ ] Manager đang chạy sẵn khi mở app → vào thẳng Manager.
- [ ] Dừng stack từ menu (có xác nhận) → app quay về màn hình khởi động.
- [ ] Link domain site mở trong trình duyệt; **Ghi hosts** gọi được helper.
- [ ] Mở app từ Finder (không qua terminal) vẫn tìm thấy `docker`.
- [ ] Trang Manager gọi `window.__TAURI__` / `invoke` → bị từ chối.
- [ ] Đóng cửa sổ → app còn trên thanh menu; **Thoát** → container vẫn chạy.
