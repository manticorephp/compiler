# W0 (fast loop) + W1 (CI) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** make every run cheap (an explicit optimisation-level policy plus a `--fast` build
path) and make the gates run on a schedule instead of by hand.

**Architecture:** no compiler-pipeline changes. One extracted container runner
(`tools/docker/gate.sh`) becomes the single definition of "what a Linux gate is", consumed by
both `tools/docker/run_tests.sh` (local) and GitHub Actions (scheduled). `bin/build --fast`
and a `-O` pass-through in `tests/aot/run.sh` make the iteration loop use `-O1`, which is
measured below.

**Tech Stack:** bash, Docker (the repo's own `Dockerfile`, `toolchain` target), GitHub Actions.

**Spec:** [`docs/design/backend-and-build-strategy.md`](../../design/backend-and-build-strategy.md)
§3 (opt-level policy) and §4 W0/W1.

## Global Constraints

- **`-O2` stays the default everywhere.** `CompileArgs::$optLevel = '2'`, `$jobs = 1`. `--fast`
  and `-O` are opt-in; no default changes.
- **`--fast` must never write `bin/manticore` or `lib/*.o`.** A failed or slow build poisons
  both, and `lib/*.o` is linked into every user program.
- **A green `-O1`/`-O0` run is not evidence about the `-O2` artifact** (the `sjlj` locals bug
  was right at `-O0`, wrong at `-O2`). Every runner prints the level it used.
- **One definition of the Linux gate.** After Task 1 the container steps exist once, in
  `tools/docker/gate.sh`; `run_tests.sh` and CI both call it. No copy in a workflow file.
- Never push. CI files land on the branch; the user decides when they reach GitHub.

## Measured baseline (2026-09-07, `7b8a02f`, macOS arm64)

| Measurement | `-O0` | `-O1` | `-O2` |
|---|---|---|---|
| self-build of the compiler (`build --apps-only`) | — | **71 s** | 83 s |
| the produced compiler's own front-end work (`analyze src`, 3×) | — | **0.937 s** | 0.909 s |
| one bench-case compile (`json_records`) | 0.357 s | 0.505 s | 0.552 s |
| that case's runtime | 1.054 s | 0.703 s | 0.710 s |

Reading: `-O1` takes **14% off the build wall** and costs **~3% on the produced compiler**.
`-O0` is faster to compile but produces a binary ~50% slower — which is why the policy is
`-O1` for iteration and `-O0` only for a debugger.

---

### Task 1: Extract the container gate into `tools/docker/gate.sh`

**Files:**
- Create: `tools/docker/gate.sh`
- Modify: `tools/docker/run_tests.sh` (drop the `RUNNER` heredoc, call the script)

**Interfaces:**
- Consumes: a container with the repo mounted read-only at `/repo` and a writable `/build`.
- Produces: `tools/docker/gate.sh`, driven by env — `MC_GATE` (0 = seed + suite, 1 = + difftest
  + fixpoint), `MC_STABILITY_N`, `MC_JOBS` (forwarded to `tests/aot/run.sh`), `MC_LOGDIR`
  (default `/build`). Exit 0 only when every stage it ran passed.

- [ ] **Step 1: Write the script** — the current heredoc body verbatim, plus: `MC_JOBS`
      forwarded, logs under `$MC_LOGDIR`, and a final `RESULT` line naming each stage's rc.
- [ ] **Step 2: Syntax gate** — `bash -n tools/docker/gate.sh && shellcheck tools/docker/gate.sh`
      (shellcheck optional; `bash -n` is not).
- [ ] **Step 3: Point `run_tests.sh` at it** — replace `-c "$RUNNER"` with
      `bash /repo/tools/docker/gate.sh`; delete the heredoc.
- [ ] **Step 4: Prove they still agree** — `bash tools/docker/run_tests.sh --shell` must still
      open a container, and `bash -n tools/docker/run_tests.sh` passes. The real proof is
      Task 5's first CI run; do not claim the gate works from a syntax check.
- [ ] **Step 5: Commit** — `git commit -m "One definition of the Linux gate: tools/docker/gate.sh"`

### Task 2: `-O` pass-through in `tests/aot/run.sh`

**Files:**
- Modify: `tests/aot/run.sh` (arg loop ~line 40, compile invocation line 117, header print)

**Interfaces:**
- Produces: `tests/aot/run.sh [-O <level>]`, default `2`, also readable as `MC_OPT`. The
  header line prints `opt=-O<level> jobs=<n>` so a pasted result says what produced it.

- [ ] **Step 1: Add the flag** — `-O*|--opt` into the `while` loop, `OPT="${MC_OPT:-2}"`,
      appended to the compile line as `-O$OPT`.
- [ ] **Step 2: Print it** in the runner's header, next to the case count.
- [ ] **Step 3: Verify the level reaches clang** — run one case at `-O0` with `-v` and confirm
      it passes; run the same case at the default and confirm the header says `opt=-O2`.
- [ ] **Step 4: Commit.**

### Task 3: `bin/build --fast`

**Files:**
- Modify: `bin/build` (flag parse, the pass-1 invocation, the swap guard)

**Interfaces:**
- Produces: `bin/build --fast [output]` — builds the compiler application **only**, at `-O1`,
  to `bin/manticore.fast` (or the given output), smoke-tests it, and **never** swaps
  `bin/manticore` or runs pass 2 (`--libs-only`). Prints how to promote a fast build
  (`bin/build` proper) and that its result is not gate evidence.

- [ ] **Step 1: Parse `--fast`**, defaulting `OUT` to `bin/manticore.fast` when it is set and
      no output was named.
- [ ] **Step 2: Refuse the dangerous combination** — `--fast` with `OUT=bin/manticore`, or with
      `--verify`, exits 2 with the reason. This is the guard that keeps a slow binary out of
      the canonical slot.
- [ ] **Step 3: Pass `-O1`** to the pass-1 `build --apps-only` (through `supports_flag`, since
      an older installed compiler may not take `-O` on `build`).
- [ ] **Step 4: Skip pass 2 and the swap** under `--fast`; keep the smoke test.
- [ ] **Step 5: Prove the guard** — `bin/build --fast bin/manticore` exits 2 and writes
      nothing; `bin/build --fast` produces `bin/manticore.fast` that compiles and runs hello
      world, with `bin/manticore` unchanged (compare `stat`/hash before and after).
- [ ] **Step 6: Commit.**

### Task 4: `.gitignore` the fast artifact

**Files:** Modify `.gitignore`

- [ ] **Step 1:** add `bin/manticore.fast`. **Step 2:** `git status` clean after a `--fast`
      build. **Step 3:** commit.

### Task 5: GitHub Actions — per-push smoke

**Files:** Create `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `Dockerfile` (`toolchain` target) and `tools/docker/gate.sh` from Task 1.
- Produces: a `ci` workflow, jobs `linux-arm64` (runner `ubuntu-24.04-arm`) and `linux-amd64`
  (`ubuntu-latest`), each: build the toolchain image with GHA layer cache → run the container
  with the checkout mounted at `/repo` and `MC_GATE=0 MC_JOBS=0` → upload
  `compile.log`/`suite.log` on failure.

- [ ] **Step 1: Write the workflow** — `on: push`, `pull_request`, `workflow_dispatch`;
      `concurrency` cancelling superseded runs; `timeout-minutes: 120`.
- [ ] **Step 2: Lint it** — `php -r` YAML check is not available; use
      `docker run --rm -v "$PWD":/w rhysd/actionlint:latest -color` if Docker is up, else
      `gh workflow view` after it lands. A workflow that fails to parse is a red run, not a
      silent skip — acceptable to learn on the first push.
- [ ] **Step 3: Commit.** Do not push; the user pushes.

### Task 6: GitHub Actions — the nightly gate

**Files:** Create `.github/workflows/nightly.yml`

**Interfaces:**
- Produces: a `nightly` workflow — `schedule` (cron, 02:00 UTC) + `workflow_dispatch`; the same
  two Linux jobs with `MC_GATE=1` (adds difftest + `selfhost_fixpoint` with
  `MC_STABILITY_N=2`), plus a `macos-14` job that installs php 8.5 + llvm from brew and runs
  `bin/compile` + `tests/aot/run.sh -j 0` + `tools/difftest.sh`.
- `timeout-minutes: 360`, artifacts always uploaded, a failure summary in `$GITHUB_STEP_SUMMARY`
  naming the commit — the state file's "which commit is this green result from?" problem is
  exactly what this closes.

- [ ] **Step 1: Write the workflow.** **Step 2: Lint as in Task 5.** **Step 3: Commit.**

### Task 7: Document the loop

**Files:** Modify `docs/ROADMAP.md` (Direction section), `docs/design/backend-and-build-strategy.md` (§3 measured table), `README.md` (a CI badge line — only after the first green run)

- [ ] **Step 1:** fold the measured `-O1`/`-O2` numbers into §3 so the policy cites evidence,
      not taste. **Step 2:** name `bin/build --fast` and `run.sh -O` where the build commands
      are listed. **Step 3:** commit.

## Self-review notes

- Spec coverage: §3 policy → Tasks 2/3/7; §4 W0 → Tasks 2–4; §4 W1 → Tasks 1/5/6.
- The one thing this plan deliberately does NOT do: change any default. Every default stays
  `-O2`/`-j1`, so no existing result is invalidated by it.
- Open risk: `ubuntu-24.04-arm` runners are free for public repositories only. If the repo is
  private when this lands, the arm64 job must move to the amd64 runner under qemu (slow) or be
  nightly-only. Decide when the first run is attempted, not before.
