#!/bin/bash
find ./scripts -name "*.sh" -type f | while read -r file; do
  echo "🛠️ Formatting $file..."
  sed -i 's/\r$//' "$file"              # Remove CR
  chmod +x "$file"                     # Add exec permission (optional)
done
