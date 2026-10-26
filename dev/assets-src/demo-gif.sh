#!/usr/bin/env bash
# Converts the frames recorded by demo-gif.js in <dir> to <dir>/demo.gif with an optimized palette.
set -euo pipefail
dir=$1
filters="fps=12,scale=900:-1:flags=lanczos"
in=(-f concat -i "$dir/frames.txt")
ffmpeg -loglevel error -y "${in[@]}" -vf "$filters,palettegen=stats_mode=full:max_colors=256" "$dir/palette.png"
ffmpeg -loglevel error -y "${in[@]}" -i "$dir/palette.png" -lavfi "$filters[x];[x][1:v]paletteuse=dither=none:diff_mode=rectangle" "$dir/demo.gif"
rm "$dir/palette.png"
ls -l "$dir/demo.gif"
