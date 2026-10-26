# The redscale CMS image: acore-cms's WordPress image with the shop plugin inside.
#
# Built in two steps, because the base image comes from the CMS's own Dockerfile and is
# not published anywhere. From the workspace root, with `cms/` as the context:
#
#   1. docker build --file cms/data/docker/Wordpress.Dockerfile \
#        --tag redscale-cms-base:<tag> cms
#   2. docker build --file cms/docker/cms.Dockerfile \
#        --build-arg BASE=redscale-cms-base:<tag> --tag redscale-cms:<tag> cms
#
# Only the derived image is tagged for a registry and pushed, so the base build's
# intermediate stages never leave the build machine.
ARG BASE
FROM ${BASE}

LABEL org.opencontainers.image.title="redscale-cms" \
      org.opencontainers.image.description="WordPress plus the redscale shop plugin"

# The plugin cannot live in /var/www/html: that path is the wordpress-src named
# volume at runtime, so anything an image puts there is hidden by the volume on
# first use. It is staged under /opt instead, and the cms-plugin-init service
# copies it into the volume before php starts.
COPY src/acore-wp-plugin /opt/redscale/wp-plugins/acore-wp-plugins

# init.sh reads optional plugin overrides from /conf/init and skips the branch
# when the directory is absent. acore-cms has no conf/init in the repository, so
# this is an empty mount point: production's plugin options are written through WP-CLI
# once the stack is up, and an operator who prefers files can mount them in from
# /srv/redscale/etc/cms-init without touching the image.
RUN mkdir -p /conf/init \
 && chown -R www-data:www-data /opt/redscale/wp-plugins /conf/init

# The image keeps acore-cms's entrypoint (apps/init/init.sh), which is what runs
# the WordPress install, activates the plugin and waits for wp-db. Only the
# one-shot plugin-init service overrides it.
