# Stage: Dependencies (inkl. Rollup-Bindings)
FROM --platform=linux/amd64 node:20-bullseye AS deps
WORKDIR /app
COPY package*.json ./
RUN npm install

# Stage: Dev (Vite + Hot Reload)
FROM deps AS dev
WORKDIR /app
COPY . .
EXPOSE 5173
# WICHTIG: Bei gemountetem node_modules-Volume ist der Ordner im Container leer.
# Wir installieren deshalb beim Start, falls node_modules leer ist:
CMD [ "sh", "-c", "test -d node_modules && ls -1 node_modules | grep -q . || npm install; npm run dev -- --host 0.0.0.0" ]

# Stage: Builder (Prod Build)
FROM deps AS builder
WORKDIR /app
COPY . .
RUN npm run build

# Stage: Production (statischer Nginx-Serve)
FROM nginx:alpine AS production
COPY --from=builder /app/dist /usr/share/nginx/html
