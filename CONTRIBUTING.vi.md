[English](CONTRIBUTING.md) | [Tiếng Việt](CONTRIBUTING.vi.md)

# Đóng góp

Cảm ơn bạn đã giúp cải thiện multi-php-docker. Mọi báo lỗi, ý tưởng tính năng, sửa tài liệu và pull request đều được hoan nghênh.

## Báo lỗi

Tạo [bug report](https://github.com/hailong289/multi-php-docker/issues/new?template=bug_report.yml) thay vì chỉ nhắn riêng, để lỗi được theo dõi và người khác dễ tìm lại. Form sẽ hỏi:

- Các bước tái hiện, kết quả mong đợi và kết quả thực tế
- Hệ điều hành, `docker --version`, `docker compose version`
- Log liên quan (`docker compose logs <service>`, lỗi trên Manager, file trong `runtime/` hoặc `php-controller-runtime/status/`)

Tìm trong [Issue đã có](https://github.com/hailong289/multi-php-docker/issues) trước. Không dán mật khẩu, token hay đường dẫn dự án riêng tư.

## Đề xuất tính năng

Tạo [feature request](https://github.com/hailong289/multi-php-docker/issues/new?template=feature_request.yml), mô tả vấn đề cần giải quyết chứ không chỉ giải pháp. Với thay đổi lớn, chờ thống nhất trên Issue rồi mới viết code.

## Nhánh

`master` chứa code đã release. Mọi thay đổi vào `develop` trước, `develop` được merge vào `master` khi release.

```bash
git checkout develop
git pull origin develop
git checkout -b feature/mo-ta-ngan
```

| Tiền tố | Dùng khi | Ví dụ |
| --- | --- | --- |
| `feature/` | Thêm hoặc cải tiến tính năng | `feature/manage-port` |
| `bugfix/` | Sửa lỗi | `bugfix/fix-path-project` |
| `docs/` | Chỉ sửa tài liệu | `docs/contributing` |
| `chore/` | Build, CI hoặc tooling | `chore/pin-runner` |

## Commit message

Theo [Conventional Commits](https://www.conventionalcommits.org/): `type(scope): tóm tắt`, viết bằng tiếng Anh ở thể mệnh lệnh, không chấm cuối câu.

```text
feat(manager): make service host ports configurable
fix(nginx): keep previous config when reload fails
docs: move contributing guide to CONTRIBUTING.md
```

Type: `feat`, `fix`, `perf`, `refactor`, `docs`, `chore`, `ci`, `test`. Scope thường dùng: `manager`, `php`, `nginx`, `tabs`, `hosts`, `ci`. Giải thích *lý do* trong phần body khi chưa rõ ràng.

## Trước khi mở PR

```bash
# Unit check Manager
docker compose run --rm --no-deps -v ./server/manager/backend:/app/backend \
  --entrypoint php manager backend/tests/run_unit_checks.php
# Build lại service đã sửa (manager, php-8.x, ...)
docker compose build <service> && docker compose up -d <service>
```

- Chuỗi UI mới: thêm vào cả `en.js` và `vi.js`.
- Không commit `env.json`, `.env`, `runtime/`, chứng chỉ, `server/source/`.

## Pull request

Push nhánh, mở PR vào **`develop`**, điền template và gắn Issue (`Fixes #123`). Mỗi PR một chủ đề; sửa UI thì kèm ảnh chụp.
