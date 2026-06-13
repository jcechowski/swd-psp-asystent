FROM node:22-alpine
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY build.mjs tsconfig.json ./
COPY src/ src/
COPY public/ public/
COPY server.js .
RUN node build.mjs
RUN npm prune --omit=dev
RUN addgroup -S app && adduser -S app -G app
RUN chown -R app:app /app
USER app
EXPOSE 3000
CMD ["node", "server.js"]
