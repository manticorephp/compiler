#compdef manticore

_manticore() {
  local -a commands
  commands=(
    'compile:Compile to a native binary (-o <out>); analyzes by default (--no-analyze skips, --analyze-strict gates on errors)'
    'build:Build all targets from a manticore.json manifest (libraries + applications)'
    'dump-llvm:Read PHP source from stdin, emit LLVM IR on stdout'
    'dump-ast:Parse PHP source and print the resulting AST'
    'analyze:Static type analysis; report diagnostics, no codegen'
    'dump-mir:Parse PHP, lower to MIR, print the typed IR'
    'dump-llvm-mir:Parse PHP, run MIR pipeline + EmitLlvm, print LLVM IR'
    'split-ir:Split a staged .ll module into N part files (dev tool)'
    'dump-sig:Parse PHP, print the module-interface .sig (exported symbol table)'
    'version:Print compiler version'
    'upgrade:Update the installed compiler to the latest release (--check only reports)'
    'doctor:Check that this compiler and its toolchain work; exit 1 on a failure'
    'completions:Print a shell completion script (bash, zsh or fish)'
    'help:Show this help text'
  )
  if (( CURRENT == 2 )); then
    if [[ $words[CURRENT] == -* ]]; then
      compadd -- --version --help
    else
      _describe -t commands 'manticore command' commands
    fi
    return
  fi
  case $words[2] in
    compile)
      _arguments \
        '--help[Show this help text]' \
        '-o+[Output path (default a.out)]:path:_files' \
        '--memory=[Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)]:mode:' \
        '--backend=[Codegen backend override]:name:' \
        '-O+[Optimisation level: 0|1|2|3|s|z (default 2)]:level:' \
        '--prelude[dump-*: include the prelude in the dump]' \
        '--effects[dump-mir: show inferred effects]' \
        '--emit-library[Emit a library object instead of an executable]' \
        '--runtime[With --emit-library: this library is the bundled stdlib]' \
        '--allow-undefined-traps[Do not refuse a library that contains undefined-function traps]' \
        '--keep-ir[Keep the intermediate .ll files]' \
        '-j+[Parallel clang jobs; 0 = one per core]:n:' \
        '--analyze[Run the analyzer (default)]' \
        '--no-analyze[Skip the analyzer]' \
        '--analyze-strict[Fail with rc 65 on analyzer errors]' \
        '--progress=[Progress output: auto|live|plain|json|off (default auto)]:mode:' \
        '--quiet[Silence progress and timings (diagnostics stay)]' \
        '--timings[Print a per-stage time table at the end]' \
        '--color=[Colour: auto|always|never (default auto)]:when:' \
        '*:file:_files'
      ;;
    build)
      _arguments \
        '--help[Show this help text]' \
        '-o+[Output path (default a.out)]:path:_files' \
        '--memory=[Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)]:mode:' \
        '--backend=[Codegen backend override]:name:' \
        '-O+[Optimisation level: 0|1|2|3|s|z (default 2)]:level:' \
        '--prelude[dump-*: include the prelude in the dump]' \
        '--effects[dump-mir: show inferred effects]' \
        '--emit-library[Emit a library object instead of an executable]' \
        '--runtime[With --emit-library: this library is the bundled stdlib]' \
        '--allow-undefined-traps[Do not refuse a library that contains undefined-function traps]' \
        '--keep-ir[Keep the intermediate .ll files]' \
        '-j+[Parallel clang jobs; 0 = one per core]:n:' \
        '--libs-only[Build library targets and stop (cold seed)]' \
        '--apps-only[Build application targets only]' \
        '--no-analyze[Accepted for compile parity; build does not run the analyzer]' \
        '--progress=[Progress output: auto|live|plain|json|off (default auto)]:mode:' \
        '--quiet[Silence progress and timings (diagnostics stay)]' \
        '--timings[Print a per-stage time table at the end]' \
        '--color=[Colour: auto|always|never (default auto)]:when:' \
        '*:file:_files'
      ;;
    dump-llvm)
      _arguments \
        '--help[Show this help text]' \
        '-o+[Output path (default a.out)]:path:_files' \
        '--memory=[Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)]:mode:' \
        '--backend=[Codegen backend override]:name:' \
        '-O+[Optimisation level: 0|1|2|3|s|z (default 2)]:level:' \
        '--prelude[dump-*: include the prelude in the dump]' \
        '--effects[dump-mir: show inferred effects]' \
        '--emit-library[Emit a library object instead of an executable]' \
        '--runtime[With --emit-library: this library is the bundled stdlib]' \
        '--allow-undefined-traps[Do not refuse a library that contains undefined-function traps]' \
        '--keep-ir[Keep the intermediate .ll files]' \
        '-j+[Parallel clang jobs; 0 = one per core]:n:' \
        '*:file:_files'
      ;;
    dump-ast)
      _arguments \
        '--help[Show this help text]' \
        '-o+[Output path (default a.out)]:path:_files' \
        '--memory=[Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)]:mode:' \
        '--backend=[Codegen backend override]:name:' \
        '-O+[Optimisation level: 0|1|2|3|s|z (default 2)]:level:' \
        '--prelude[dump-*: include the prelude in the dump]' \
        '--effects[dump-mir: show inferred effects]' \
        '--emit-library[Emit a library object instead of an executable]' \
        '--runtime[With --emit-library: this library is the bundled stdlib]' \
        '--allow-undefined-traps[Do not refuse a library that contains undefined-function traps]' \
        '--keep-ir[Keep the intermediate .ll files]' \
        '-j+[Parallel clang jobs; 0 = one per core]:n:' \
        '--tolerant[Recover from syntax errors; print the tree and every error]' \
        '--ranges[Print spans as start-end ranges]' \
        '--names[Print the written-name table instead of the tree]' \
        '--check-names[Verify every name span slices to its written text; print each mismatch, exit 1 if any]' \
        '--check-ranges[Verify the range invariants; print each violation, exit 1 if any]' \
        '*:file:_files'
      ;;
    analyze)
      _arguments \
        '--help[Show this help text]' \
        '-o+[Output path (default a.out)]:path:_files' \
        '--memory=[Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)]:mode:' \
        '--backend=[Codegen backend override]:name:' \
        '-O+[Optimisation level: 0|1|2|3|s|z (default 2)]:level:' \
        '--prelude[dump-*: include the prelude in the dump]' \
        '--effects[dump-mir: show inferred effects]' \
        '--emit-library[Emit a library object instead of an executable]' \
        '--runtime[With --emit-library: this library is the bundled stdlib]' \
        '--allow-undefined-traps[Do not refuse a library that contains undefined-function traps]' \
        '--keep-ir[Keep the intermediate .ll files]' \
        '-j+[Parallel clang jobs; 0 = one per core]:n:' \
        '--deep[Also run the compiler'\''s MIR type passes (heavier)]' \
        '--json[Machine-readable output]' \
        '--baseline=[Suppress findings listed in this baseline file]:path:_files' \
        '--generate-baseline=[Write a baseline file from current findings]:path:_files' \
        '--only=[Keep only diagnostic codes starting with these prefixes (comma list); gates on any survivor]:prefixes:' \
        '*:file:_files'
      ;;
    dump-mir)
      _arguments \
        '--help[Show this help text]' \
        '-o+[Output path (default a.out)]:path:_files' \
        '--memory=[Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)]:mode:' \
        '--backend=[Codegen backend override]:name:' \
        '-O+[Optimisation level: 0|1|2|3|s|z (default 2)]:level:' \
        '--prelude[dump-*: include the prelude in the dump]' \
        '--effects[dump-mir: show inferred effects]' \
        '--emit-library[Emit a library object instead of an executable]' \
        '--runtime[With --emit-library: this library is the bundled stdlib]' \
        '--allow-undefined-traps[Do not refuse a library that contains undefined-function traps]' \
        '--keep-ir[Keep the intermediate .ll files]' \
        '-j+[Parallel clang jobs; 0 = one per core]:n:' \
        '*:file:_files'
      ;;
    dump-llvm-mir)
      _arguments \
        '--help[Show this help text]' \
        '-o+[Output path (default a.out)]:path:_files' \
        '--memory=[Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)]:mode:' \
        '--backend=[Codegen backend override]:name:' \
        '-O+[Optimisation level: 0|1|2|3|s|z (default 2)]:level:' \
        '--prelude[dump-*: include the prelude in the dump]' \
        '--effects[dump-mir: show inferred effects]' \
        '--emit-library[Emit a library object instead of an executable]' \
        '--runtime[With --emit-library: this library is the bundled stdlib]' \
        '--allow-undefined-traps[Do not refuse a library that contains undefined-function traps]' \
        '--keep-ir[Keep the intermediate .ll files]' \
        '-j+[Parallel clang jobs; 0 = one per core]:n:' \
        '*:file:_files'
      ;;
    split-ir)
      _arguments \
        '--help[Show this help text]' \
        '*:file:_files'
      ;;
    dump-sig)
      _arguments \
        '--help[Show this help text]' \
        '-o+[Output path (default a.out)]:path:_files' \
        '--memory=[Memory mode: rc|arena|hybrid (default: $MANTICORE_MEMORY)]:mode:' \
        '--backend=[Codegen backend override]:name:' \
        '-O+[Optimisation level: 0|1|2|3|s|z (default 2)]:level:' \
        '--prelude[dump-*: include the prelude in the dump]' \
        '--effects[dump-mir: show inferred effects]' \
        '--emit-library[Emit a library object instead of an executable]' \
        '--runtime[With --emit-library: this library is the bundled stdlib]' \
        '--allow-undefined-traps[Do not refuse a library that contains undefined-function traps]' \
        '--keep-ir[Keep the intermediate .ll files]' \
        '-j+[Parallel clang jobs; 0 = one per core]:n:' \
        '*:file:_files'
      ;;
    version)
      _arguments \
        '--help[Show this help text]' \
        '--check[Report installed and latest version (same as upgrade --check); exit 1 if an update exists, 2 on error]' \
        '*:file:_files'
      ;;
    upgrade)
      _arguments \
        '--help[Show this help text]' \
        '--check[Report installed and latest version; change nothing. Exit 1 if an update exists, 2 on error]' \
        '--version=[Install this release instead of the latest]:X:' \
        '*:file:_files'
      ;;
    doctor)
      _arguments \
        '--help[Show this help text]' \
        '--offline[Skip the update check]' \
        '--no-smoke[Skip compiling and running a hello-world]' \
        '*:file:_files'
      ;;
    completions)
      _values 'shell' bash zsh fish
      ;;
  esac
}

if [[ $funcstack[1] == _manticore ]]; then
  _manticore "$@"
elif (( $+functions[compdef] )); then
  compdef _manticore manticore
fi
