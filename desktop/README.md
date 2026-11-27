# Multi PHP Desktop (Tauri)

> Trạng thái: **bản đầu (macOS)** đã build được. Windows dùng cùng code, chưa build thử.

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
│ ● Docker   Đang tắt   [Khởi động Docker]      │
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
| **Khởi động Docker** | Docker chưa chạy | Chạy ngầm, không bật cửa sổ. macOS: dò app chứa lệnh `docker` theo symlink; OrbStack → `orbctl start`, Docker Desktop → `docker desktop start --detach`; lệnh đó lỗi hoặc engine khác → `open -g -j <app>` (mở ẩn). Windows: chạy `Docker Desktop.exe` |
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
├─ Sites ▸                (domain trong env.json, mở bằng trình duyệt)
├─ ─────────
├─ Khởi động stack        (docker compose up -d)
├─ Khởi động lại stack    (docker compose restart)
├─ Dừng stack…            (docker compose stop, hỏi xác nhận)
├─ ─────────
├─ Mở thư mục dự án
├─ Đổi thư mục dự án…
└─ Thoát
```

- **Dừng stack** dùng `docker compose stop` (không xoá container/network), luôn hỏi xác nhận.
- **Thoát** chỉ đóng app, không dừng Docker hay container.
- Đóng cửa sổ: app vẫn ở trên thanh menu; **Thoát** mới tắt hẳn.
- Chữ trong menu, hộp thoại và thông báo theo ngôn ngữ chọn ở màn hình khởi động (VI/EN).
- Icon có chấm trạng thái: xanh = Manager chạy, vàng = đang chạy lệnh compose, xám = tắt.

### 5. Tính năng phụ

- **Container:** khi Docker chạy, màn hình khởi động liệt kê container của stack (`docker compose ps -a`) kèm trạng thái và CPU/RAM (`docker stats --no-stream`, 5 giây một lần). Mỗi dòng có:
  - **Bật / Khởi động lại** (`docker compose start|restart <tên>`),
  - **Terminal**: mở Terminal của hệ điều hành với shell trong container (`docker compose exec <tên> bash`, không có bash thì `sh`),
  - **Log**: theo dõi log trực tiếp (`docker compose logs -f --tail 200`), có nút dừng.
  Tên container từ trang luôn được đối chiếu với danh sách của stack trước khi chạy lệnh.
- **Container tự dừng:** container đang chạy mà dừng (không phải do app ra lệnh) → thông báo hệ điều hành.
- **Mở nhanh:** thư mục dự án (Finder/Explorer) và VS Code, ở chân trang.
- **Dọn dẹp Docker:** `docker system prune -f` sau hộp xác nhận. Không xoá volume (dữ liệu database).
- **Ngôn ngữ Manager:** app mở Manager kèm `?lang=vi|en`, Manager lưu lại ngôn ngữ đó.
- **Cổng 8080 bị chiếm:** hiện tên và PID chương trình đang giữ cổng (`lsof` trên macOS/Linux, `netstat` + `tasklist` trên Windows).
- **Thông báo:** compose chạy xong (hoặc lỗi) khi cửa sổ không được focus → thông báo hệ điều hành.
- **Mở cùng máy:** ô chọn ở chân trang, mặc định tắt. Mở lúc đăng nhập thì cửa sổ ẩn (`--hidden`), chỉ hiện trên thanh menu; app không tự chạy stack.
- **Tự cập nhật:** lúc mở, app hỏi `releases/latest/download/latest.json` trên GitHub; có bản mới thì hiện thanh **Cập nhật và khởi động lại**. Không có mạng hoặc chưa có Release thì không hiện gì.

## Hai chế độ: stack đóng gói hoặc repo có sẵn

- **Stack đóng gói (mặc định):** người dùng chỉ chọn **thư mục source** (code các project PHP). App mang sẵn Docker stack (compose, Dockerfile, cấu hình, mã Manager; khoảng 2 MB) và cài vào `~/.multi-php/stack`. File `.env` ở đó có `SOURCE_DIR=<thư mục source>` và `COMPOSE_PROJECT_NAME=multi-php`.
- **Repo có sẵn:** trỏ vào một bản clone `multi-php-docker`, chạy như trước. Không đặt `SOURCE_DIR` thì compose dùng `./server/source` như cũ.
- Đổi qua lại bằng link ở chân trang. Đang chạy stack thì phải dừng trước (tên container trùng nhau).

**Gói stack lúc build:** `scripts/copy-stack.mjs` (chạy trước `tauri build`/`tauri dev`) chép các file Git theo dõi của stack vào `src-tauri/stack/` và ghi `.stack-version` (hash nội dung). App cài lại stack khi hash khác bản đã cài, tức sau mỗi lần cập nhật app có stack mới.

**Cập nhật stack không làm mất dữ liệu:**
- File sinh ra lúc chạy (`env.json`, `.env`, `runtime/`, `nginx/ssl`, log, phiên bản PHP thêm từ Manager) không có trong gói nên không bị đụng tới.
- `php.ini`, `mysql/my.cnf`, `postgres/postgresql.conf` chỉ được chép lần đầu, sau đó giữ bản của người dùng.
- `docker-compose.yml` được thay bằng bản mới nhưng giữ lại các dòng `include` mà Manager đã thêm.
- Dữ liệu MySQL/Postgres nằm trong Docker volume có tên cố định (`mysql-data`, …) nên dùng chung cho cả hai chế độ.

**Thư mục source trong compose:** container PHP, nginx (`/var/www/source`) và Manager (`/var/host-project/server/source`) gắn `${SOURCE_DIR:-./server/source}`. Script tạo container của Manager (`php-controller.sh`, `DockerExec::composeUpService`) đổi cả phần mặc định `:-./` sang đường dẫn host.

## Kiến trúc

```
desktop/
├─ README.md                 tài liệu này
├─ package.json              @tauri-apps/cli
├─ app-icon.svg              nguồn icon (`npx tauri icon app-icon.svg`)
├─ src/                      màn hình khởi động (HTML/JS tĩnh, không framework)
│  ├─ index.html
│  ├─ main.js
│  └─ style.css
└─ src-tauri/
   ├─ tauri.conf.json
   ├─ capabilities/          quyền IPC theo cửa sổ
   ├─ icons/
   └─ src/
      ├─ main.rs           cửa sổ, lệnh IPC, điều hướng
      ├─ docker.rs           kiểm tra Docker, chạy lệnh compose
      └─ tray.rs             menu thanh menu / khay
```

- **Tauri v2.** Plugin: `dialog` (chọn thư mục, hỏi xác nhận), `opener` (mở link ngoài và `multi-php-hosts:`). Thư mục dự án lưu ở `config.json` trong thư mục cấu hình của app (macOS: `~/Library/Application Support/vn.posapp.multiphp/`).
- **Màn hình khởi động** là trang tĩnh đóng gói trong app, gọi Rust qua `invoke`. Không dùng Vue/PrimeVue để app nhẹ và không phụ thuộc frontend Manager.
- **Lệnh Rust** (danh sách cố định):

| Lệnh | Việc |
| --- | --- |
| `pick_project_dir()` | Hộp thoại chọn thư mục, kiểm tra có `docker-compose.yml` của Multi PHP rồi lưu |
| `status()` | Thư mục dự án, Docker có cài / đang chạy, cổng 8080 là `closed` \| `manager` \| `other` (gọi `GET /api/session`) |
| `open_docker()` | Khởi động Docker engine chạy ngầm |
| `compose(action)` | `action` ∈ `up` \| `build` \| `stop`; chạy trong thư mục dự án, đẩy từng dòng output qua event `compose-log`, trả về mã thoát. Mỗi lúc chỉ một lệnh |
| `open_manager()` | Chuyển cửa sổ sang Manager |

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
npx tauri dev                          # chạy thử
npx tauri build --bundles app,dmg      # ra .app và .dmg trong src-tauri/target/release/bundle/
```

Windows: build trên máy Windows (cần Rust + Visual Studio Build Tools + WebView2), ra `.msi`/`.exe`.

## CI/CD và phát hành

Workflow: [`.github/workflows/build-desktop.yml`](../.github/workflows/build-desktop.yml).

| Khi nào | Làm gì |
| --- | --- |
| Push thay đổi trong `desktop/` lên `master`/`main`, hoặc chạy tay (Actions → Build Desktop App → Run workflow) | Chạy test Rust, build macOS (Apple Silicon + Intel) và Windows; file để ở mục **Artifacts** của lần chạy (giữ 14 ngày) |
| Push tag `desktop-vX.Y.Z` | Build như trên rồi tạo **GitHub Release** kèm file tải: `.dmg` (aarch64, x64), `-setup.exe`, `.msi` |

Phát hành bản mới:

1. Tăng `version` trong `src-tauri/tauri.conf.json` (và `package.json`, `src-tauri/Cargo.toml` cho khớp), commit.
2. Tạo và đẩy tag đúng phiên bản; tag lệch phiên bản thì workflow dừng:

   ```bash
   git tag desktop-v0.1.0
   git push origin desktop-v0.1.0
   ```

3. File tải ở trang **Releases** của repo.

### Tự cập nhật: khoá ký

Bản cập nhật được ký bằng khoá riêng (không phải chứng chỉ Apple/Windows). Public key nằm trong `tauri.conf.json` (`plugins.updater.pubkey`); private key ở `~/.tauri/multi-php-updater.key` trên máy tạo khoá, **không commit**. Mất private key thì các bản đã cài không nhận cập nhật được nữa: hãy lưu nó vào trình quản lý mật khẩu.

- CI cần secret `TAURI_SIGNING_PRIVATE_KEY` (nội dung file key) và `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` (để trống nếu key không có mật khẩu):
  ```bash
  gh secret set TAURI_SIGNING_PRIVATE_KEY < ~/.tauri/multi-php-updater.key
  gh secret set TAURI_SIGNING_PRIVATE_KEY_PASSWORD --body ""
  ```
- Build ở máy cũng cần biến này (thiếu thì `tauri build` báo lỗi):
  ```bash
  export TAURI_SIGNING_PRIVATE_KEY="$(cat ~/.tauri/multi-php-updater.key)" TAURI_SIGNING_PRIVATE_KEY_PASSWORD=""
  ```
- Endpoint là Release **mới nhất** của repo, nên repo không được phát hành Release nào khác ngoài `desktop-v*`.
- Phát hành bản mới: tăng `version` trong `tauri.conf.json`, rồi tag `desktop-vX.Y.Z` như trên.

App chưa ký số Apple/Windows (macOS chỉ ký ad-hoc), nên lần đầu mở sẽ bị chặn. Cách mở có trong mô tả mỗi Release. Ký số và notarize thật cần Apple Developer ID / chứng chỉ Windows: thêm secret rồi cấu hình `tauri-action` sau.

## Kiểm tra trước khi coi là xong

- [ ] Docker tắt → nút **Khởi động Docker** bật được engine (không hiện cửa sổ), trạng thái tự chuyển khi Docker sẵn sàng.
- [ ] Manager dừng → **Khởi động** chạy `docker compose up -d`, log hiện đúng, xong thì **Mở Manager** sáng.
- [x] Manager đang chạy sẵn khi mở app → vào thẳng Manager.
- [ ] Dừng stack từ menu (có xác nhận) → app quay về màn hình khởi động.
- [ ] Link domain site mở trong trình duyệt; **Ghi hosts** gọi được helper.
- [x] Mở app với PATH tối thiểu (như khi mở từ Finder) vẫn tìm thấy `docker`.
- [x] Trang Manager gọi `invoke` → bị từ chối (`not allowed`).
- [ ] Đóng cửa sổ → app còn trên thanh menu; **Thoát** → container vẫn chạy.
- [ ] Máy chưa có repo: chọn thư mục source → **Khởi động** build và chạy stack từ `~/.multi-php/stack`, site trong thư mục source mở được.
- [ ] Manager tạo/cài thêm container PHP ở chế độ stack đóng gói → container gắn đúng thư mục source.
- [ ] Windows: chế độ stack đóng gói (đường dẫn `C:/…` trong `SOURCE_DIR`).
