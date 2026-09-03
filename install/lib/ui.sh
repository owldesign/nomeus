#!/usr/bin/env bash
# nomeus — installer UI. Sourced by install.sh (and usable by any script). bash 3.2 (macOS) safe.
#
#   ui_init [logfile]            colours if stdout is a tty and NO_COLOR is unset; opens the log
#   ui_banner "1.3.0"            the wordmark
#   ui_step "label" cmd args…    runs quietly (output → log) with a spinner; ✓ label (1.2s) or ✗ + last log lines, exit 1
#   ui_step_visible "label" cmd… same, but output stays on the terminal (steps that may sudo-prompt)
#   ui_done "label" ["detail"]   already in place — nothing to do
#   ui_info / ui_warn "text"
#   ui_hint "text"               shown under the next failing step (the fix)
#   ui_summary "line"…           the closing card
#
# UI_VERBOSE=1 streams every step's output (no spinner). UI_SOFT=1 makes ui_step return the exit code instead of exiting.

UI_TTY=0; UI_LOG="${UI_LOG:-/tmp/nomeus-install.log}"; UI_HINT_TEXT=""; UI_STEPS=0; UI_START=$SECONDS
_ui_c() { :; }

ui_init() {
  UI_LOG="${1:-$UI_LOG}"
  mkdir -p "$(dirname "$UI_LOG")" 2>/dev/null || true
  { printf '\n── nomeus install · %s ──\n' "$(date '+%Y-%m-%d %H:%M:%S')"; } >> "$UI_LOG" 2>/dev/null || UI_LOG=/dev/null
  if [[ -t 1 && -z "${NO_COLOR:-}" && "${TERM:-dumb}" != "dumb" ]]; then
    UI_TTY=1
    C_GOLD=$'\033[1;33m'; C_GREEN=$'\033[32m'; C_RED=$'\033[31m'; C_BLUE=$'\033[34m'; C_DIM=$'\033[2m'; C_BOLD=$'\033[1m'; C_OFF=$'\033[0m'
  else
    C_GOLD=""; C_GREEN=""; C_RED=""; C_BLUE=""; C_DIM=""; C_BOLD=""; C_OFF=""
  fi
}

ui_banner() {
  local v="${1:-}"
  if [[ "$UI_TTY" -eq 1 ]]; then
    printf '%s' "$C_GOLD"
    cat <<'ART'

  ███╗   ██╗  ██████╗  ███╗   ███╗ ███████╗ ██╗   ██╗ ███████╗
  ████╗  ██║ ██╔═══██╗ ████╗ ████║ ██╔════╝ ██║   ██║ ██╔════╝
  ██╔██╗ ██║ ██║   ██║ ██╔████╔██║ █████╗   ██║   ██║ ███████╗
  ██║╚██╗██║ ██║   ██║ ██║╚██╔╝██║ ██╔══╝   ██║   ██║ ╚════██║
  ██║ ╚████║ ╚██████╔╝ ██║ ╚═╝ ██║ ███████╗ ╚██████╔╝ ███████║
  ╚═╝  ╚═══╝  ╚═════╝  ╚═╝     ╚═╝ ╚══════╝  ╚═════╝  ╚══════╝
ART
    printf '%s' "$C_OFF"
    printf '  %sνομεύς · shepherd for your local stack%s%s\n\n' "$C_DIM" "$C_OFF" "${v:+  $C_DIM$v$C_OFF}"
  else
    printf 'nomeus%s\n\n' "${v:+ $v}"
  fi
}

ui_info() { printf '  %s%s%s\n' "$C_DIM" "$*" "$C_OFF"; }
ui_warn() { printf '  %s!%s %s\n' "$C_GOLD" "$C_OFF" "$*"; }
ui_hint() { UI_HINT_TEXT="$*"; }
ui_done() { UI_STEPS=$((UI_STEPS+1)); printf '  %s·%s %-44s %s%s%s\n' "$C_DIM" "$C_OFF" "$1" "$C_DIM" "${2:-already in place}" "$C_OFF"; }

_ui_elapsed() { local s=$(( SECONDS - $1 )); if [[ $s -ge 60 ]]; then printf '%dm%02ds' $((s/60)) $((s%60)); else printf '%ds' "$s"; fi; }

_ui_fail() { # _ui_fail label
  if [[ "$UI_TTY" -eq 1 ]]; then printf '\r  %s✗%s %s\n' "$C_RED" "$C_OFF" "$1"; else printf '  [!!] %s\n' "$1"; fi
  printf '%s' "$C_DIM"; tail -n 30 "$UI_LOG" 2>/dev/null | sed 's/^/      /'; printf '%s' "$C_OFF"
  [[ -n "$UI_HINT_TEXT" ]] && printf '  %s→ %s%s\n' "$C_GOLD" "$UI_HINT_TEXT" "$C_OFF"
  printf '  %slog: %s%s\n' "$C_DIM" "$UI_LOG" "$C_OFF"
  UI_HINT_TEXT=""
}

ui_step() { # ui_step "label" cmd args…
  local label="$1"; shift
  local start=$SECONDS rc=0
  UI_STEPS=$((UI_STEPS+1))
  printf '\n▶ %s\n' "$label" >> "$UI_LOG"
  if [[ "${UI_VERBOSE:-0}" -eq 1 || "$UI_TTY" -eq 0 ]]; then
    [[ "$UI_TTY" -eq 0 ]] && printf '  [..] %s\n' "$label"
    [[ "${UI_VERBOSE:-0}" -eq 1 ]] && printf '  %s▶ %s%s\n' "$C_GOLD" "$label" "$C_OFF"
    if [[ "${UI_VERBOSE:-0}" -eq 1 ]]; then "$@" 2>&1 | tee -a "$UI_LOG"; rc=${PIPESTATUS[0]}; else "$@" >> "$UI_LOG" 2>&1; rc=$?; fi
  else
    ( "$@" >> "$UI_LOG" 2>&1 ) &
    local pid=$! frames='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏' i=0
    while kill -0 "$pid" 2>/dev/null; do
      printf '\r  %s%s%s %s' "$C_GOLD" "${frames:$((i % 10)):1}" "$C_OFF" "$label"
      i=$((i+1)); sleep 0.1
    done
    wait "$pid"; rc=$?
  fi
  if [[ $rc -eq 0 ]]; then
    if [[ "$UI_TTY" -eq 1 ]]; then printf '\r  %s✓%s %-44s %s%s%s\n' "$C_GREEN" "$C_OFF" "$label" "$C_DIM" "$(_ui_elapsed "$start")" "$C_OFF"
    else printf '  [ok] %s (%s)\n' "$label" "$(_ui_elapsed "$start")"; fi
    UI_HINT_TEXT=""
  else
    _ui_fail "$label"
    [[ "${UI_SOFT:-0}" -eq 1 ]] || exit 1
  fi
  return $rc
}

ui_step_visible() { # for steps that may ask for a password
  local label="$1"; shift
  local start=$SECONDS rc=0
  UI_STEPS=$((UI_STEPS+1))
  printf '  %s▶%s %s\n' "$C_GOLD" "$C_OFF" "$label"
  printf '\n▶ %s (visible)\n' "$label" >> "$UI_LOG"
  "$@" 2>&1 | tee -a "$UI_LOG" | sed 's/^/      /'; rc=${PIPESTATUS[0]}
  if [[ $rc -eq 0 ]]; then
    printf '  %s✓%s %-44s %s%s%s\n' "$C_GREEN" "$C_OFF" "$label" "$C_DIM" "$(_ui_elapsed "$start")" "$C_OFF"
    UI_HINT_TEXT=""
  else
    printf '  %s✗%s %s\n' "$C_RED" "$C_OFF" "$label"
    [[ -n "$UI_HINT_TEXT" ]] && printf '  %s→ %s%s\n' "$C_GOLD" "$UI_HINT_TEXT" "$C_OFF"
    UI_HINT_TEXT=""
    [[ "${UI_SOFT:-0}" -eq 1 ]] || exit 1
  fi
  return $rc
}

ui_summary() { # ui_summary "line"…
  local line
  printf '\n  %s%s%s\n' "$C_DIM" "────────────────────────────────────────────────────────" "$C_OFF"
  for line in "$@"; do printf '  %s\n' "$line"; done
  printf '  %s%d step(s) · %s%s\n\n' "$C_DIM" "$UI_STEPS" "$(_ui_elapsed "$UI_START")" "$C_OFF"
}
