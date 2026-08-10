# Profiling side image: the gate toolchain plus a heap profiler.
#
# Built FROM the same `manticore-toolchain:<arch>` the Linux gate uses, so the
# compiler under measurement is the gate's compiler. The root Dockerfile is
# deliberately NOT edited — it is the image every gate run depends on, and a
# profiler has no business in it (this follows tools/docker/iopoll/).
ARG BASE=manticore-toolchain:arm64
FROM ${BASE}

RUN apt-get update && apt-get install -y --no-install-recommends \
        heaptrack \
    && rm -rf /var/lib/apt/lists/*
