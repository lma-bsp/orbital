# shellcheck shell=bash
# build-identity.sh — release identity build args for the laravel image.
#
# Sourced by the local build scripts (local/k3d-build.sh,
# scripts/build-and-push.sh, and any deploy/build.sh of your own). Sets
# the ORBITAL_BUILD_ARGS array to pass to `docker build`:
#
#   --build-arg ORBITAL_COMMIT=<git rev-parse HEAD>
#   --build-arg ORBITAL_SOURCE_URL=<public URL of the remote HEAD is on>
#
# Whoever builds the image owns the source URL (AGPL section 13): the
# URL is the remote the running commit was pushed to, so /source in the
# deployed app links code people can actually fetch. Set
# ORBITAL_SOURCE_URL in the environment to override the guess.
#
# Usage:
#   source scripts/lib/build-identity.sh
#   orbital_build_identity "$REPO_ROOT"
#   docker build "${ORBITAL_BUILD_ARGS[@]}" ...

# Turn a git remote URL into a browsable https URL with no credentials:
#   git@host:org/repo.git            -> https://host/org/repo
#   ssh://git@host:2222/org/repo.git -> https://host/org/repo
#   https://user:token@host/org/repo -> https://host/org/repo
orbital_public_remote_url() {
    local url="$1"
    case "$url" in
        ssh://*)
            url="${url#ssh://}"
            url="${url#*@}"
            local host="${url%%/*}"
            url="https://${host%%:*}/${url#*/}"
            ;;
        *://*)
            local scheme="${url%%://*}" rest="${url#*://}"
            [[ "$rest" == *@* && "${rest%%/*}" == *@* ]] && rest="${rest#*@}"
            url="${scheme}://${rest}"
            ;;
        *@*:*)
            url="${url#*@}"
            url="https://${url%%:*}/${url#*:}"
            ;;
    esac
    url="${url%/}"
    echo "${url%.git}"
}

orbital_build_identity() {
    local repo="${1:-.}"
    local commit source_url="${ORBITAL_SOURCE_URL:-}"

    commit="$(git -C "$repo" rev-parse HEAD 2>/dev/null || true)"
    if [[ -z "$commit" ]]; then
        echo "WARN: not a git checkout; image will report 'commit not recorded'." >&2
    elif [[ -n "$(git -C "$repo" status --porcelain --untracked-files=no 2>/dev/null)" ]]; then
        echo "WARN: uncommitted changes; ${commit:0:12} does not describe this build exactly." >&2
    fi

    if [[ -z "$source_url" && -n "$commit" ]]; then
        # Remotes with a branch that contains HEAD, preferring origin.
        local remotes remote=""
        remotes="$(git -C "$repo" branch -r --contains HEAD --format='%(refname:short)' 2>/dev/null \
            | grep -v '/HEAD$' | cut -d/ -f1 | sort -u)"
        if grep -qx origin <<<"$remotes"; then
            remote=origin
        else
            remote="$(head -n1 <<<"$remotes")"
        fi

        if [[ -n "$remote" ]]; then
            source_url="$(orbital_public_remote_url "$(git -C "$repo" remote get-url "$remote")")"
        else
            echo "WARN: ${commit:0:12} is on no remote branch, so nobody can fetch its source." >&2
            echo "      Push it to a public remote first, or set ORBITAL_SOURCE_URL." >&2
        fi
    fi

    ORBITAL_BUILD_ARGS=(
        --build-arg "ORBITAL_COMMIT=${commit}"
        --build-arg "ORBITAL_SOURCE_URL=${source_url}"
    )
    echo "==> source offer: ${source_url:-<upstream default>} @ ${commit:0:12}" >&2
}
