FROM node:20-bullseye AS deps

WORKDIR /app

COPY package*.json ./
RUN npm ci


FROM deps AS dev

WORKDIR /app
COPY . .

EXPOSE 5173

CMD ["sh", "-c", "test -d node_modules && ls -1 node_modules | grep -q . || npm ci; npm run dev -- --host 0.0.0.0"]


FROM deps AS builder

WORKDIR /app
COPY . .
RUN npm run build


FROM nginx:alpine AS production

COPY nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=builder /app/dist /usr/share/nginx/html

EXPOSE 80
