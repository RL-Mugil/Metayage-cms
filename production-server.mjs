import { createServer } from "node:http";

const { default: handler } = await import("./dist/server/server.js");

const PORT = parseInt(process.env.PORT || "3000", 10);
const HOST = process.env.HOST || "127.0.0.1";

const server = createServer(async (req, res) => {
  try {
    const proto = req.headers["x-forwarded-proto"] || "https";
    const host = req.headers["x-forwarded-host"] || req.headers.host || "localhost";
    const url = new URL(req.url, `${proto}://${host}`);

    const headers = new Headers();
    for (const [key, value] of Object.entries(req.headers)) {
      if (value !== undefined) {
        headers.append(key, Array.isArray(value) ? value.join(", ") : value);
      }
    }

    let body = null;
    if (req.method !== "GET" && req.method !== "HEAD") {
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      if (chunks.length > 0) body = Buffer.concat(chunks);
    }

    const request = new Request(url.toString(), {
      method: req.method,
      headers,
      body: body || null,
      // @ts-ignore — Node 22 supports this flag for streaming bodies
      ...(body ? { duplex: "half" } : {}),
    });

    const response = await handler.fetch(request, {}, {});

    const resHeaders = {};
    response.headers.forEach((value, key) => {
      resHeaders[key] = value;
    });
    res.writeHead(response.status, resHeaders);

    if (response.body) {
      const reader = response.body.getReader();
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        res.write(value);
      }
    }
    res.end();
  } catch (error) {
    console.error("[production-server] Unhandled error:", error);
    if (!res.headersSent) {
      res.writeHead(500, { "content-type": "text/plain" });
    }
    res.end("Internal Server Error");
  }
});

server.listen(PORT, HOST, () => {
  console.log(`MYPL-CMS frontend → http://${HOST}:${PORT}`);
});
