# Thiết kế khởi động Docker Compose không cần `.env`

## Mục tiêu

Người dùng chỉ cần chạy:

```sh
docker compose up -d
```

Stack phải khởi động được, Server Manager hoạt động và có thể tạo các container PHP tùy chọn mà không cần chạy `ensure_hosts_env.*` trước. Việc tích hợp với file hosts của hệ điều hành là tính năng tùy chọn vì nó cần quyền và tiến trình chạy trực tiếp trên máy host.

## Phạm vi

Thiết kế loại bỏ `HOST_PROJECT_PATH` và `HOSTS_FILE` khỏi các điều kiện bắt buộc để khởi động stack. `env-init` tiếp tục chỉ chịu trách nhiệm tạo `env.json`; nó không tạo `.env`, vì Docker Compose đã nội suy biến và giải quyết bind mount trước khi container này chạy.

Thiết kế không cố cho container sửa trực tiếp file hosts của macOS hoặc Windows. Cơ chế helper/protocol hiện có vẫn được giữ như một tích hợp tùy chọn, có quyền riêng biệt.

## Kiến trúc

### Đường dẫn project trên host

`php-controller` luôn mount repository vào `/project`. Khi tạo một PHP container tùy chọn, controller đọc metadata mount của chính `php_controller_container` qua Docker API để lấy `Source` tương ứng với `Destination=/project`. Giá trị đó là đường dẫn thật trên Docker host và được dùng để biến các bind source tương đối trong Compose thành đường dẫn tuyệt đối.

Nếu người dùng vẫn đặt `HOST_PROJECT_PATH`, giá trị tường minh này được ưu tiên để giữ tương thích ngược. Khi không thể suy ra đường dẫn, controller trả trạng thái thất bại và ghi thông báo chẩn đoán, thay vì yêu cầu `.env` tồn tại.

### File hosts của hệ điều hành

Service `manager` không phụ thuộc vào bind mount `HOSTS_FILE`. Trạng thái domain được đọc từ `runtime/hosts.status.json`, là kết quả do helper chạy trên host ghi lại. Khi chưa từng chạy helper, trạng thái hiển thị là chưa xác định và UI vẫn cung cấp các dòng hosts để sao chép thủ công.

Các thao tác quản lý server, domain, Nginx và PHP container vẫn hoạt động khi chưa cấu hình helper. Nút ghi hosts bằng quyền admin kiểm tra khả năng tích hợp; nếu protocol/helper chưa được đăng ký, UI hướng dẫn chạy bước thiết lập một lần hoặc sử dụng hướng dẫn thủ công.

### Tương thích ngược

`scripts/ensure_hosts_env.sh`, `ensure_hosts_env.ps1` và các wrapper Compose vẫn tồn tại cho người dùng muốn tự động đăng ký helper. File `.env` cũ vẫn được chấp nhận nhưng không còn là điều kiện để stack hay nút tạo PHP hoạt động.

## Luồng dữ liệu

1. `docker compose up -d` mount repository vào `env-init`, `manager` và `php-controller` bằng đường dẫn tương đối do Compose tự giải quyết.
2. `env-init` tạo `env.json` nếu thiếu.
3. Khi UI gửi yêu cầu tạo PHP, `php-controller` suy ra đường dẫn host từ mount `/project`, tạo cấu hình Compose tạm và gọi `docker compose create` mà không bắt buộc `--env-file`.
4. Khi người dùng yêu cầu ghi hosts, Manager tạo `runtime/hosts.sync` và mở protocol host nếu đã đăng ký.
5. Helper host cập nhật file hosts, ghi `runtime/hosts.status.json`; Manager dùng file trạng thái này để cập nhật UI.
6. Nếu helper không khả dụng, UI hiển thị đường dẫn và các dòng cần thêm thủ công.

## Xử lý lỗi

- Không suy ra được source của `/project`: action tạo PHP thất bại, giữ trạng thái container hiện tại và ghi log nguyên nhân.
- Không có `.env`: bỏ qua `--env-file`; Compose dùng default trong YAML.
- Chưa có trạng thái hosts: hiển thị `unknown`, không trả lỗi làm gián đoạn trang Manager.
- Helper/protocol chưa đăng ký hoặc người dùng từ chối quyền admin: hiển thị hướng dẫn thiết lập một lần và phương án sao chép thủ công.
- File trạng thái hosts hỏng: bỏ qua dữ liệu hỏng và hiển thị `unknown`.

## Kiểm thử

- Shell test xác minh `php-controller` suy ra host project từ metadata mount khi `HOST_PROJECT_PATH=/project`.
- Shell test xác minh lệnh tạo container không truyền `--env-file` khi `/project/.env` không tồn tại.
- Backend test xác minh bootstrap và danh sách domain không lỗi khi không có hosts mount hoặc file trạng thái.
- Backend test xác minh file `hosts.status.json` hợp lệ vẫn được đọc và file hỏng được xử lý an toàn.
- Kiểm thử Compose bằng cấu hình không có `.env`: `docker compose config`, `docker compose up -d`, và tạo một PHP profile tùy chọn qua controller.
- Kiểm tra hồi quy với `.env` cũ để bảo đảm cấu hình tường minh vẫn được ưu tiên.

## Tiêu chí hoàn thành

- Một checkout mới không có `.env` khởi động thành công bằng `docker compose up -d`.
- Manager tải được đầy đủ và không báo lỗi hosts chỉ vì thiếu `HOSTS_FILE`.
- Nút tạo PHP 7.4, 8.0 và 8.1 hoạt động mà không cần `HOST_PROJECT_PATH` trong `.env`.
- Người dùng vẫn nhận được hướng dẫn rõ ràng để ghi hosts qua helper hoặc thủ công.
- Cấu hình `.env` hiện có tiếp tục hoạt động.
