# bash completion for manticore
_manticore_files() {
    local IFS=$'\n'
    COMPREPLY=($(compgen -f -- "$1"))
}

_manticore() {
    local cur prev cmd
    COMPREPLY=()
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD-1]}"
    if [ "$COMP_CWORD" -eq 1 ]; then
        COMPREPLY=($(compgen -W "compile build dump-llvm dump-ast analyze dump-mir dump-llvm-mir split-ir dump-sig version upgrade doctor completions help --version --help" -- "$cur"))
        return 0
    fi
    cmd="${COMP_WORDS[1]}"
    case "$cmd" in
        compile)
            case "$prev" in
                -o) _manticore_files "$cur"; return 0 ;;
                --memory|--backend|-O|-j|--progress|--color) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help -o --memory --backend -O --prelude --effects --emit-library --runtime --allow-undefined-traps --keep-ir -j --analyze --no-analyze --analyze-strict --progress --quiet --timings --color" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        build)
            case "$prev" in
                -o) _manticore_files "$cur"; return 0 ;;
                --memory|--backend|-O|-j|--progress|--color) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help -o --memory --backend -O --prelude --effects --emit-library --runtime --allow-undefined-traps --keep-ir -j --libs-only --apps-only --no-analyze --progress --quiet --timings --color" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        dump-llvm)
            case "$prev" in
                -o) _manticore_files "$cur"; return 0 ;;
                --memory|--backend|-O|-j) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help -o --memory --backend -O --prelude --effects --emit-library --runtime --allow-undefined-traps --keep-ir -j" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        dump-ast)
            case "$prev" in
                -o) _manticore_files "$cur"; return 0 ;;
                --memory|--backend|-O|-j) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help -o --memory --backend -O --prelude --effects --emit-library --runtime --allow-undefined-traps --keep-ir -j" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        analyze)
            case "$prev" in
                -o|--baseline|--generate-baseline) _manticore_files "$cur"; return 0 ;;
                --memory|--backend|-O|-j|--only) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help -o --memory --backend -O --prelude --effects --emit-library --runtime --allow-undefined-traps --keep-ir -j --deep --json --baseline --generate-baseline --only" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        dump-mir)
            case "$prev" in
                -o) _manticore_files "$cur"; return 0 ;;
                --memory|--backend|-O|-j) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help -o --memory --backend -O --prelude --effects --emit-library --runtime --allow-undefined-traps --keep-ir -j" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        dump-llvm-mir)
            case "$prev" in
                -o) _manticore_files "$cur"; return 0 ;;
                --memory|--backend|-O|-j) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help -o --memory --backend -O --prelude --effects --emit-library --runtime --allow-undefined-traps --keep-ir -j" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        split-ir)
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        dump-sig)
            case "$prev" in
                -o) _manticore_files "$cur"; return 0 ;;
                --memory|--backend|-O|-j) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help -o --memory --backend -O --prelude --effects --emit-library --runtime --allow-undefined-traps --keep-ir -j" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        version)
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help --check" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        upgrade)
            case "$prev" in
                --version) return 0 ;;
            esac
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help --check --version" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        doctor)
            if [[ "$cur" == -* ]]; then
                COMPREPLY=($(compgen -W "--help --offline --no-smoke" -- "$cur"))
            else
                _manticore_files "$cur"
            fi
            ;;
        completions)
            COMPREPLY=($(compgen -W "bash zsh fish" -- "$cur"))
            return 0
            ;;
    esac
    return 0
}

complete -o filenames -F _manticore manticore
