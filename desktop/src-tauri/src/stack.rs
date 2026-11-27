//! The Docker stack bundled with the app, installed to ~/.multi-php/stack. The user only picks a source folder.
use std::io;
use std::path::{Path, PathBuf};

/// Files the Manager or the user edit in place: written on first install, never replaced by an update.
fn keep_existing(rel: &str) -> bool {
    rel.ends_with("/php.ini") || rel == "mysql/my.cnf" || rel == "postgres/postgresql.conf"
}

/// ~/.multi-php/stack (no spaces in the path: compose and the stack's shell scripts get it unquoted).
pub fn stack_dir() -> Option<PathBuf> {
    let home = std::env::var_os(if cfg!(windows) { "USERPROFILE" } else { "HOME" })?;
    Some(PathBuf::from(home).join(".multi-php").join("stack"))
}

fn files(dir: &Path, base: &Path, out: &mut Vec<PathBuf>) -> io::Result<()> {
    for entry in std::fs::read_dir(dir)? {
        let path = entry?.path();
        if path.is_dir() {
            files(&path, base, out)?;
        } else {
            out.push(path.strip_prefix(base).unwrap().to_path_buf());
        }
    }
    Ok(())
}

/// `- path: compose/x.yml` include entries (two lines each) of a docker-compose.yml.
fn includes(yml: &str) -> Vec<String> {
    yml.lines()
        .filter(|l| l.trim_start().starts_with("- path: compose/"))
        .map(|l| l.trim().trim_start_matches("- path: ").to_string())
        .collect()
}

/// Puts back include entries the Manager added (PHP versions installed from its catalog) into a new docker-compose.yml.
fn merge_includes(old: &str, new: &str) -> String {
    let present = includes(new);
    let extra: String = includes(old)
        .into_iter()
        .filter(|p| !present.contains(p))
        .map(|p| format!("  - path: {p}\n    project_directory: .\n"))
        .collect();
    match new.find("\nservices:") {
        Some(i) if !extra.is_empty() => format!("{}\n{extra}{}", new[..i].trim_end_matches('\n'), &new[i..]),
        _ => new.to_string(),
    }
}

/// Copies the bundled stack over `target` when its .stack-version differs. Files the stack creates at runtime
/// (env.json, .env, runtime/, ssl, logs, added PHP versions) are not in the bundle, so they are never touched.
pub fn install(bundled: &Path, target: &Path) -> io::Result<bool> {
    let version = std::fs::read_to_string(bundled.join(".stack-version"))?;
    if std::fs::read_to_string(target.join(".stack-version")).is_ok_and(|v| v == version) {
        return Ok(false);
    }
    let mut list = vec![];
    files(bundled, bundled, &mut list)?;
    for rel in list {
        let (from, to) = (bundled.join(&rel), target.join(&rel));
        let rel_str = rel.to_string_lossy().replace('\\', "/");
        if to.exists() && keep_existing(&rel_str) {
            continue;
        }
        std::fs::create_dir_all(to.parent().unwrap())?;
        if rel_str == "docker-compose.yml" && to.exists() {
            let merged = merge_includes(&std::fs::read_to_string(&to)?, &std::fs::read_to_string(&from)?);
            std::fs::write(&to, merged)?;
            continue;
        }
        std::fs::copy(&from, &to)?;
    }
    // ponytail: files dropped from a newer bundle stay behind; harmless for compose/scripts, clean up if that changes.
    Ok(true)
}

/// Path form for SOURCE_DIR in .env. Compose accepts forward slashes on Windows too.
pub fn env_path(dir: &Path) -> String {
    dir.display().to_string().replace('\\', "/")
}

/// Sets `KEY=value` lines in the stack's .env, keeping every other line (the Manager writes there too).
pub fn set_env(stack: &Path, pairs: &[(&str, &str)]) -> io::Result<()> {
    let file = stack.join(".env");
    let mut lines: Vec<String> = std::fs::read_to_string(&file).unwrap_or_default().lines().map(String::from).collect();
    for (key, value) in pairs {
        let line = format!("{key}={value}");
        match lines.iter_mut().find(|l| l.split('=').next() == Some(key)) {
            Some(existing) => *existing = line,
            None => lines.push(line),
        }
    }
    std::fs::write(file, lines.join("\n") + "\n")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn install_updates_but_keeps_user_files() {
        let tmp = std::env::temp_dir().join(format!("mpd-stack-{}", std::process::id()));
        let (bundle, target) = (tmp.join("bundle"), tmp.join("target"));
        std::fs::create_dir_all(bundle.join("configs/php8")).unwrap();
        std::fs::write(bundle.join(".stack-version"), "v1").unwrap();
        std::fs::write(bundle.join("configs/php8/php.ini"), "bundled").unwrap();
        std::fs::write(bundle.join("docker-compose.yml"), "include:\n  - path: compose/php-8.2.yml\n    project_directory: .\n\nservices:\n  a: {}\n").unwrap();
        assert!(install(&bundle, &target).unwrap());
        assert!(!install(&bundle, &target).unwrap(), "same version is a no-op");

        // User edits php.ini, Manager adds a PHP version and env.json; then the app updates.
        std::fs::write(target.join("configs/php8/php.ini"), "mine").unwrap();
        std::fs::write(target.join("env.json"), "{}").unwrap();
        let compose = std::fs::read_to_string(target.join("docker-compose.yml")).unwrap();
        std::fs::write(target.join("docker-compose.yml"), compose.replace("\nservices:", "  - path: compose/php-9.0.yml\n    project_directory: .\n\nservices:")).unwrap();
        std::fs::write(bundle.join(".stack-version"), "v2").unwrap();
        std::fs::write(bundle.join("docker-compose.yml"), "include:\n  - path: compose/php-8.2.yml\n    project_directory: .\n\nservices:\n  b: {}\n").unwrap();
        assert!(install(&bundle, &target).unwrap());

        let compose = std::fs::read_to_string(target.join("docker-compose.yml")).unwrap();
        assert!(compose.contains("  b: {}") && compose.contains("compose/php-9.0.yml") && compose.contains("compose/php-8.2.yml"));
        assert_eq!(compose.matches("php-8.2").count(), 1);
        assert_eq!(std::fs::read_to_string(target.join("configs/php8/php.ini")).unwrap(), "mine");
        assert!(target.join("env.json").exists());
        std::fs::remove_dir_all(&tmp).unwrap();
    }

    #[test]
    fn env_lines_are_upserted() {
        let tmp = std::env::temp_dir().join(format!("mpd-env-{}", std::process::id()));
        std::fs::create_dir_all(&tmp).unwrap();
        std::fs::write(tmp.join(".env"), "NGINX_SCALE=1\nSOURCE_DIR=/old\n").unwrap();
        set_env(&tmp, &[("SOURCE_DIR", "/new"), ("COMPOSE_PROJECT_NAME", "multi-php")]).unwrap();
        assert_eq!(std::fs::read_to_string(tmp.join(".env")).unwrap(), "NGINX_SCALE=1\nSOURCE_DIR=/new\nCOMPOSE_PROJECT_NAME=multi-php\n");
        std::fs::remove_dir_all(&tmp).unwrap();
    }
}
