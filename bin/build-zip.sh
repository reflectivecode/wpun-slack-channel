#!/bin/sh
# Build the release zip: build/wpun-slack-channel-<version>.zip, with the plugin directory at the top and its
# SHA-256 checksum beside it. The version comes from the plugin header.
#
# The zip holds only what the plugin needs at run time. The file order and times are fixed (SOURCE_DATE_EPOCH, by default
# the time of the last commit), so building the same commit twice gives the same zip.
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
slug=wpun-slack-channel
files="wpun-slack-channel.php class-slack-channel.php README.md LICENSE"

version=$(sed -n 's/^ \* Version: *//p' "$root/$slug.php" | head -n 1 | tr -d '\r ')
if [ -z "$version" ]; then
	echo "Could not read the version from $slug.php" >&2
	exit 1
fi

epoch="${SOURCE_DATE_EPOCH:-$(git -C "$root" log -1 --format=%ct 2>/dev/null || date +%s)}"
stamp=$(date -u -d "@$epoch" +%Y%m%d%H%M.%S 2>/dev/null || date -u -r "$epoch" +%Y%m%d%H%M.%S)

out="$root/build"
stage="$out/stage"
rm -rf "$stage"
mkdir -p "$stage/$slug"
for f in $files; do
	cp "$root/$f" "$stage/$slug/$f"
done
find "$stage" -exec touch -t "$stamp" {} +

zip_name="$slug-$version.zip"
rm -f "$out/$zip_name" "$out/$zip_name.sha256"
( cd "$stage" && find "$slug" -type f | LC_ALL=C sort | zip -X -q "$out/$zip_name" -@ )
( cd "$out" && sha256sum "$zip_name" > "$zip_name.sha256" )
rm -rf "$stage"

echo "$out/$zip_name"
cat "$out/$zip_name.sha256"
