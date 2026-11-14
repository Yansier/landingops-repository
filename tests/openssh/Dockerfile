FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-client openssh-server python3 coreutils findutils procps \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/ssh/landingops-readonly-wrapper /usr/local/libexec/landingops-readonly-wrapper
COPY deploy/ssh/landingops-readonly-probe-v2 /usr/local/libexec/landingops-readonly-probe-v2
COPY tests/openssh/entrypoint.sh /usr/local/libexec/landingops-openssh-test-entrypoint
RUN chmod 0755 /usr/local/libexec/landingops-readonly-wrapper \
    /usr/local/libexec/landingops-readonly-probe-v2 \
    /usr/local/libexec/landingops-openssh-test-entrypoint

EXPOSE 2222
ENTRYPOINT ["/usr/local/libexec/landingops-openssh-test-entrypoint"]
