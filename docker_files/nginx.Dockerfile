FROM nginx:alpine

# envsubst (gettext) and jq. One layer, no package index left behind.
RUN apk add --no-cache jq gettext

COPY nginx/examples /etc/nginx/examples
COPY env.example.json /var/environment/env.json
COPY scripts/ /var/scripts/

CMD ["nginx", "-g", "daemon off;"]
