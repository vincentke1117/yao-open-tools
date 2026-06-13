import type { FastifyInstance, FastifyReply, FastifyRequest } from "fastify";
import type { Redis } from "ioredis";
import type { AppConfig } from "../config.js";
import type { DbClient } from "../db/client.js";
import { enqueueClick } from "../services/analytics.js";
import { getRedirectLink } from "../services/links.js";
import { getSiteSettings, siteSettingsCacheKeys } from "../services/settings.js";
import { isValidCustomSlug } from "../utils/slug.js";

interface RouteContext {
  config: AppConfig;
  db: DbClient;
  redis: Redis;
}

const redirectAnalyticsCacheTtlSeconds = 60;
const redirectTrackingDelayMs = 850;
const redirectTrackingFallbackMs = 1_400;
const redirectTrackingCsp = [
  "default-src 'self' https: data: blob:",
  "script-src 'self' https: 'unsafe-inline' 'unsafe-eval'",
  "style-src 'self' https: 'unsafe-inline'",
  "img-src 'self' https: data: blob:",
  "connect-src 'self' https:",
  "frame-src https:",
  "base-uri 'none'",
  "form-action 'none'",
  "frame-ancestors 'self'",
  "upgrade-insecure-requests"
].join("; ");

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (character) => {
    switch (character) {
      case "&":
        return "&amp;";
      case "<":
        return "&lt;";
      case ">":
        return "&gt;";
      case '"':
        return "&quot;";
      case "'":
        return "&#39;";
      default:
        return character;
    }
  });
}

function toSafeJsonLiteral(value: string): string {
  return JSON.stringify(value).replace(/</g, "\\u003c").replace(/>/g, "\\u003e").replace(/&/g, "\\u0026");
}

async function getRedirectAnalyticsCode(context: RouteContext): Promise<string> {
  const cached = await context.redis.get(siteSettingsCacheKeys.redirectAnalyticsCode).catch(() => null);
  if (cached !== null) {
    return cached;
  }

  const settings = await getSiteSettings({ db: context.db });
  const code = settings.redirectAnalyticsEnabled ? settings.analyticsCode.trim() : "";
  await context.redis.set(siteSettingsCacheKeys.redirectAnalyticsCode, code, "EX", redirectAnalyticsCacheTtlSeconds).catch(() => null);
  return code;
}

function sendDirectRedirect(reply: FastifyReply, context: RouteContext, targetUrl: string) {
  return reply.status(context.config.redirectStatus).header("Location", targetUrl).header("Cache-Control", "no-store").send();
}

export function renderTrackedRedirectPage(input: { targetUrl: string; slug: string; analyticsCode: string }): string {
  const targetUrl = toSafeJsonLiteral(input.targetUrl);
  const slug = toSafeJsonLiteral(input.slug);
  const escapedTargetUrl = escapeHtml(input.targetUrl);
  const escapedSlug = escapeHtml(input.slug);

  return `<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <meta http-equiv="refresh" content="3;url=${escapedTargetUrl}" />
    <title>正在跳转 - ${escapedSlug}</title>
    ${input.analyticsCode}
    <script>
      (() => {
        const targetUrl = ${targetUrl};
        const slug = ${slug};
        let redirected = false;
        const redirect = () => {
          if (redirected) return;
          redirected = true;
          window.location.replace(targetUrl);
        };
        const trackRedirect = () => {
          try {
            const pagePath = window.location.pathname + window.location.search + window.location.hash;
            if (typeof window.gtag === "function") {
              window.gtag("event", "tokurl_redirect", {
                event_category: "TokURL",
                event_label: slug,
                page_location: window.location.href,
                page_path: pagePath,
                transport_type: "beacon"
              });
            }
            if (Array.isArray(window._hmt)) {
              window._hmt.push(["_trackEvent", "TokURL", "redirect", slug]);
              window._hmt.push(["_trackPageview", pagePath]);
            }
            if (typeof window.plausible === "function") {
              window.plausible("tokurl_redirect", { props: { slug } });
            }
          } catch {
            // Redirect must never depend on third-party analytics code.
          }
        };
        window.addEventListener("load", () => {
          trackRedirect();
          window.setTimeout(redirect, ${redirectTrackingDelayMs});
        });
        window.setTimeout(redirect, ${redirectTrackingFallbackMs});
      })();
    </script>
    <style>
      :root { color-scheme: light; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
      body { min-height: 100vh; margin: 0; display: grid; place-items: center; background: #f6f8fb; color: #151922; }
      main { width: min(420px, calc(100vw - 48px)); padding: 28px; border: 1px solid #e5e7eb; border-radius: 16px; background: #fff; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.10); }
      h1 { margin: 0 0 10px; font-size: 22px; }
      p { margin: 0 0 18px; color: #6b7280; line-height: 1.7; }
      a { color: #1677ff; font-weight: 700; word-break: break-all; }
    </style>
  </head>
  <body>
    <main>
      <h1>正在跳转</h1>
      <p>正在记录访问并跳转到目标页面。如果没有自动跳转，请点击下面的链接。</p>
      <a href="${escapedTargetUrl}" rel="nofollow noreferrer">继续访问</a>
    </main>
  </body>
</html>`;
}

export async function registerRedirectRoute(app: FastifyInstance, context: RouteContext) {
  async function redirectHandler(request: FastifyRequest, reply: FastifyReply) {
    const { slug } = request.params as { slug: string };

    if (!isValidCustomSlug(slug)) {
      return reply.status(404).send({
        error: "not_found",
        message: "Short link was not found."
      });
    }

    const link = await getRedirectLink(context, slug);

    if (!link) {
      return reply.status(404).send({
        error: "not_found",
        message: "Short link was not found."
      });
    }

    if (context.config.analyticsEnabled) {
      void enqueueClick(context.redis, {
        linkId: link.id,
        slug: link.slug,
        referrer: request.headers.referer ?? null,
        userAgent: request.headers["user-agent"] ?? null,
        ip: request.ip,
        hashSalt: context.config.hashSalt
      }).catch((error) => request.log.warn({ error, slug }, "Failed to enqueue click analytics"));
    }

    if (request.method === "HEAD") {
      return sendDirectRedirect(reply, context, link.targetUrl);
    }

    const analyticsCode = await getRedirectAnalyticsCode(context).catch((error) => {
      request.log.warn({ error, slug }, "Failed to load redirect analytics code");
      return "";
    });

    if (!analyticsCode) {
      return sendDirectRedirect(reply, context, link.targetUrl);
    }

    return reply
      .status(200)
      .type("text/html; charset=utf-8")
      .header("Cache-Control", "no-store")
      .header("Content-Security-Policy", redirectTrackingCsp)
      .send(renderTrackedRedirectPage({ targetUrl: link.targetUrl, slug: link.slug, analyticsCode }));
  }

  app.route({
    method: ["GET", "HEAD"],
    url: "/:slug",
    handler: redirectHandler
  });
}
