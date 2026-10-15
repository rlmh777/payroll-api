#!/usr/bin/env bash
export DOCKER_HOST="unix://$HOME/.docker/run/docker.sock"
exec vendor/laravel/sail/bin/sail "$@"

