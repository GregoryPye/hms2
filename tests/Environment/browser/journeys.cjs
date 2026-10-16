require("./guard.cjs").preflight();
const { chromium, expect } = require("@playwright/test");
const assert = require("node:assert/strict");
const origin = "http://application:8080";
const fixture = JSON.parse(process.env.HMS_BROWSER_FIXTURE);
const scenario = process.env.HMS_BROWSER_SCENARIO,
  size = process.env.HMS_BROWSER_VIEWPORT;
const viewports = {
  desktop: { width: 1280, height: 900 },
  mobile: { width: 390, height: 844 },
};
assert(Object.hasOwn(viewports, size), "Unexpected viewport");
assert(
  ["visibility", "booking", "grant", "denial", "maintainer"].includes(scenario),
  "Unexpected journey",
);
const bookings = "/api/tools/" + fixture.tool + "/bookings";
function waitForResponse(page, predicate) {
  const pending = page.waitForResponse(predicate);
  // Register a rejection handler immediately. An earlier click failure must not
  // turn a pending response wait into an unhandled rejection during teardown.
  pending.catch(() => {});
  return pending;
}
async function login(page, who) {
  await page.goto(origin + "/tools");
  await expect(page.locator("#loginForm")).toBeVisible();
  await page.locator("#login").fill("test_browser_" + who);
  await page.locator("#password").fill("password");
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith("/login")),
    page.getByRole("button", { name: "Sign in" }).click(),
  ]);
  await page.goto(origin + "/tools");
}
async function request(page, path, method = "GET", data = null, csrf = true) {
  return page.evaluate(
    async ({ path, method, data, csrf }) => {
      const headers = { Accept: "application/json" };
      if (csrf) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) headers["X-CSRF-TOKEN"] = meta.content;
        else {
          const cookie = document.cookie
            .split("; ")
            .find((value) => value.startsWith("XSRF-TOKEN="));
          if (cookie)
            headers["X-XSRF-TOKEN"] = decodeURIComponent(
              cookie.slice("XSRF-TOKEN=".length),
            );
        }
      }
      if (data !== null) headers["Content-Type"] = "application/json";
      const response = await fetch(path, {
        method,
        headers,
        body: data === null ? null : JSON.stringify(data),
      });
      const text = await response.text();
      let body;
      try {
        body = JSON.parse(text);
      } catch {
        body = null;
      }
      return { status: response.status, body };
    },
    { path, method, data, csrf },
  );
}
async function logout(page) {
  await request(page, "/logout", "POST", {});
}
async function currentBookings(page) {
  const result = await request(
    page,
    bookings +
      "?" +
      new URLSearchParams({
        start: fixture.date + "T00:00:00",
        end: fixture.date + "T23:59:59",
      }),
  );
  assert.equal(result.status, 200, "Booking read must succeed");
  return result.body;
}
async function settleCalendar(page) {
  await expect(page.locator(".fc-timegrid")).toBeVisible();
  await page.waitForFunction(
    () =>
      window.__hmsBrowser &&
      window.__hmsBrowser.app.$children.some(
        (v) => v.$options._componentTag === "tool-calendar",
      ),
  );
  // The unchanged component schedules a 100 ms updateSize/scrollTo06 workaround.
  // Let that specific callback finish before navigating/scrolling for pointer input.
  await page.waitForTimeout(150);
  await page.evaluate((date) => {
    const component = window.__hmsBrowser.app.$children.find(
      (v) => v.$options._componentTag === "tool-calendar",
    );
    component.calendarApi.gotoDate(date);
    component.calendarApi.scrollToTime("12:00");
  }, fixture.date);
  await page.waitForFunction(
    () =>
      !window.__hmsBrowser.app.$children.find(
        (v) => v.$options._componentTag === "tool-calendar",
      ).isLoading,
  );
  await expect(page.locator(".vld-overlay:visible")).toHaveCount(0);
  await page
    .locator('.fc-timegrid-slot-lane[data-time="13:00:00"]')
    .evaluate((el) => el.scrollIntoView({ block: "center" }));
}
async function navigateCalendar(page) {
  await page.goto(origin + "/tools/" + fixture.tool + "/bookings");
  await settleCalendar(page);
}

async function slots(page, time) {
  const day = await page
    .locator('.fc-timegrid-col[data-date="' + fixture.date + '"]')
    .boundingBox();
  const slot = await page
    .locator('.fc-timegrid-slot-lane[data-time="' + time + '"]')
    .boundingBox();
  assert(day && slot, "Real FullCalendar cells missing");
  return { x: day.x + day.width / 2, y: slot.y + 2 };
}
async function drag(page, from, to) {
  await page.evaluate(
    ({ from, to }) => {
      const at = document.elementFromPoint(from.x, from.y);
      window.__hmsBrowser.pointer = {
        from,
        to,
        startClass: at?.getAttribute("class") || at?.tagName,
        date: at?.closest("[data-date]")?.getAttribute("data-date"),
        viewport: { width: innerWidth, height: innerHeight },
      };
    },
    { from, to },
  );
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  await page.mouse.move(to.x, to.y, { steps: 12 });
  // Let the actual calendar paint its drag mirror before mouseup calls the
  // component's confirmation callback; an instantaneous drag can skip that paint.
  await page.evaluate(
    () =>
      new Promise((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(resolve)),
      ),
  );
  await page.mouse.up();
  await page.evaluate(
    () =>
      new Promise((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(resolve)),
      ),
  );
}

async function visibility(page) {
  await login(page, "reader");
  await expect(
    page.getByRole("link", { name: "Synthetic browser tool", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Synthetic hidden tool", exact: true }),
  ).toHaveCount(0);
  assert.equal(
    (await page.goto(origin + "/tools/" + fixture.tool)).status(),
    200,
  );
  await expect(page.locator("main h1")).toHaveText("browser");
  await expect(
    page.getByRole("link", { name: "Edit", exact: true }),
  ).toHaveCount(0);
  await logout(page);
  await login(page, "admin");
  await expect(
    page.getByRole("link", { name: "Synthetic hidden tool", exact: true }),
  ).toBeVisible();
  await page.goto(origin + "/tools/" + fixture.tool);
  await expect(
    page.getByRole("link", { name: "Edit", exact: true }),
  ).toBeVisible();
  await logout(page);
  await page.goto(origin + "/tools");
  await expect(page.locator("#loginForm")).toBeVisible();
}
async function booking(page) {
  await login(page, "admin");
  await navigateCalendar(page);
  const initialView = await page.evaluate(
    () =>
      window.__hmsBrowser.app.$children.find(
        (v) => v.$options._componentTag === "tool-calendar",
      ).calendarApi.view.type,
  );
  assert.equal(
    initialView,
    size === "mobile" ? "timeGridDay" : "timeGridWeek",
    "Actual responsive FullCalendar view",
  );
  // Real pointer selection, real Bootstrap confirmation and real Axios HTTP request.
  const selectionEnd = await slots(page, "13:30:00");
  selectionEnd.y -= 4;
  await drag(page, await slots(page, "13:00:00"), selectionEnd);
  await expect(page.locator(".popover .btn-booking-normal")).toBeVisible();
  const created = waitForResponse(
    page,
    (r) => r.url().endsWith(bookings) && r.request().method() === "POST",
  );
  await page.locator(".popover .btn-booking-normal").click();
  assert.equal((await created).status(), 201);
  let rows = await currentBookings(page);
  assert.equal(rows.length, 1);
  const id = rows[0].id;
  assert.equal(Date.parse(rows[0].start), Date.parse(fixture.start));
  assert.equal(Date.parse(rows[0].end), Date.parse(fixture.end));
  await page.reload();
  await settleCalendar(page);
  await expect(page.locator(".fc-timegrid-event")).toHaveCount(1);
  const event = page.locator(".fc-timegrid-event");
  await expect(page.locator(".vld-overlay:visible")).toHaveCount(0);
  await event.evaluate((el) => el.scrollIntoView({ block: "center" }));
  const box = await event.boundingBox(),
    a = await slots(page, "13:00:00"),
    b = await slots(page, "14:00:00");
  let patched = waitForResponse(
    page,
    (r) =>
      r.url().endsWith(bookings + "/" + id) && r.request().method() === "PATCH",
  );
  await drag(
    page,
    { x: box.x + box.width / 2, y: box.y + 8 },
    { x: box.x + box.width / 2, y: box.y + 8 + b.y - a.y },
  );
  assert.equal((await patched).status(), 200);
  rows = await currentBookings(page);
  assert.equal(Date.parse(rows[0].start), Date.parse(fixture.start) + 3600000);
  assert.equal(Date.parse(rows[0].end), Date.parse(fixture.end) + 3600000);
  await expect(page.locator(".vld-overlay:visible")).toHaveCount(0);
  await event.evaluate((el) => el.scrollIntoView({ block: "center" }));
  await event.hover();
  const resizeHandle = event.locator(".fc-event-resizer-end");
  await expect(resizeHandle).toBeVisible();
  const resize = await resizeHandle.boundingBox();
  const c = await slots(page, "14:30:00"),
    d = await slots(page, "14:45:00");
  assert(resize, "Owned event resize handle missing");
  patched = waitForResponse(
    page,
    (r) =>
      r.url().endsWith(bookings + "/" + id) && r.request().method() === "PATCH",
  );
  await drag(
    page,
    { x: resize.x + resize.width / 2, y: resize.y + resize.height / 2 },
    {
      x: resize.x + resize.width / 2,
      y: resize.y + resize.height / 2 + d.y - c.y,
    },
  );
  assert.equal((await patched).status(), 200);
  rows = await currentBookings(page);
  assert.equal(Date.parse(rows[0].end), Date.parse(fixture.end) + 4500000);
  // Validation failure travels through actual browser cookies/CSRF and must not add a row.
  assert.equal(
    (
      await request(page, bookings, "POST", {
        start: fixture.start,
        end: fixture.end,
        type: "INVALID",
      })
    ).status,
    422,
  );
  assert.equal((await currentBookings(page)).length, 1);
  await expect(page.locator(".vld-overlay:visible")).toHaveCount(0);
  await event.click();
  const cancelled = waitForResponse(
    page,
    (r) =>
      r.url().endsWith(bookings + "/" + id) &&
      r.request().method() === "DELETE",
  );
  await page.locator(".popover .btn-danger").click();
  assert.equal((await cancelled).status(), 204);
  await expect(event).toHaveCount(0);
  assert.equal((await currentBookings(page)).length, 0);
}
async function grant(page) {
  await login(page, "admin");
  const row = page.locator("tr").filter({
    has: page.getByRole("link", {
      name: "Synthetic browser tool",
      exact: true,
    }),
  });
  await row
    .getByRole("button", { name: "Appoint Maintainer", exact: true })
    .click();
  const modal = page.locator(".modal.show");
  await expect(modal).toBeVisible();
  await modal.locator(".select2-selection").click();
  const response = waitForResponse(
    page,
    (r) =>
      r.url().includes("/api/search/users") && r.request().method() === "GET",
  );
  await page.locator(".select2-search__field").fill("browser_target");
  assert.equal((await response).status(), 200);
  await page
    .locator(".select2-results__option[aria-selected]")
    .filter({ hasText: "test_browser_target" })
    .click();
  const sent = waitForResponse(
    page,
    (r) =>
      r.url().endsWith("/tools/" + fixture.tool + "/grant") &&
      r.request().method() === "POST",
  );
  await modal
    .getByRole("button", { name: "Appoint Maintainer", exact: true })
    .click();
  assert.equal((await sent).status(), 302);
  await page.waitForLoadState("networkidle");
  await page.goto(origin + "/tools/" + fixture.tool + "/users/MAINTAINER");
  await expect(page.locator("main")).toContainText("Synthetic Target");
  await expect(
    page.locator('main a[href$="/admin/users/' + fixture.target + '"]'),
  ).toHaveCount(1);
}
async function denial(page) {
  await page.goto(origin + "/login");
  assert.equal(
    (
      await request(
        page,
        "/login",
        "POST",
        { login: "test_browser_admin", password: "password" },
        false,
      )
    ).status,
    419,
    "Real web CSRF must reject missing token",
  );
  assert.equal(
    (
      await request(
        page,
        bookings + "?start=" + fixture.date + "&end=" + fixture.date,
      )
    ).status,
    401,
    "Anonymous browser API request must be unauthenticated",
  );
  assert.equal(
    (
      await request(
        page,
        "/login",
        "POST",
        {
          login: "test_browser_admin",
          password: "password",
          _token: "invalid-test-token",
        },
        false,
      )
    ).status,
    419,
    "Invalid web CSRF token must be rejected",
  );
  await page.locator("#login").fill("test_browser_denied");
  await page.locator("#password").fill("wrong-password");
  await Promise.all([
    page.waitForNavigation(),
    page.getByRole("button", { name: "Sign in" }).click(),
  ]);
  await expect(page.locator(".invalid-feedback")).toContainText("credentials");
  await login(page, "denied");
  const denied = await page.goto(origin + "/tools");
  await expect(page).toHaveURL(origin + "/home");
  const rejected = denied.request().redirectedFrom();
  assert(
    rejected && new URL(rejected.url()).pathname === "/tools",
    "HTML denial must redirect from tools",
  );
  assert.equal((await rejected.response()).status(), 302);
  await expect(page.locator("main")).toContainText("Unauthorized");
  assert.equal(
    (
      await request(
        page,
        bookings + "?start=" + fixture.date + "&end=" + fixture.date,
      )
    ).status,
    403,
  );
  assert.equal(
    (
      await request(page, bookings, "POST", {
        start: fixture.start,
        end: fixture.end,
        type: "NORMAL",
      })
    ).status,
    403,
  );
  assert.equal(
    (
      await request(
        page,
        "/tools/" + fixture.tool + "/grant",
        "POST",
        { _method: "PATCH", grantType: "MAINTAINER", user_id: fixture.target },
        false,
      )
    ).status,
    419,
  );
}
async function maintainer(page) {
  // Create through actual authenticated requests. Existing booking journeys cover
  // DOM creation; this one owns real-plugin cross-member and self cancellation.
  await login(page,"admin");
  const own=await request(page,bookings,"POST",{start:fixture.start,end:fixture.end,type:"NORMAL"});
  assert.equal(own.status,201);await logout(page);
  await login(page,"reader");
  const later=milliseconds=>new Date(Date.parse(fixture.start)+milliseconds).toISOString();
  const other=await request(page,bookings,"POST",{start:later(3600000),end:later(5400000),type:"NORMAL"});
  assert.equal(other.status,201);
  assert.equal((await request(page,bookings+"/"+own.body.id,"DELETE")).status,403);
  await logout(page);await login(page,"admin");
  await page.goto(origin+"/tools/"+fixture.tool+"/bookings");await settleCalendar(page);
  const memberEvent=page.locator(".fc-timegrid-event").filter({hasText:"Reader"});
  await expect(memberEvent).toHaveCount(1);await memberEvent.click();
  await expect(page.locator(".popover:visible")).toHaveCount(1);
  await expect(page.locator(".popover:visible")).toContainText("member will be notified");
  // Dismiss must not remove anything, then confirm exactly one actual DELETE.
  await page.locator(".popover:visible .btn-outline-dark").click();
  assert.equal((await currentBookings(page)).length,2);
  await memberEvent.click();
  let response=waitForResponse(page,r=>r.url().endsWith(bookings+"/"+other.body.id)&&r.request().method()==="DELETE");
  await page.locator(".popover:visible .btn-danger").click();assert.equal((await response).status(),204);
  await expect(memberEvent).toHaveCount(0);
  // A fresh page resolves a fresh real plugin instance. It must offer one owner
  // prompt without falsely promising an email for self-cancellation.
  await page.reload();await settleCalendar(page);
  const ownEvent=page.locator(".fc-timegrid-event");await expect(ownEvent).toHaveCount(1);await ownEvent.click();
  await expect(page.locator(".popover:visible")).toHaveCount(1);
  await expect(page.locator(".popover:visible")).toContainText("Would you like to cancel this booking?");
  await expect(page.locator(".popover:visible")).not.toContainText("member will be notified");
  response=waitForResponse(page,r=>r.url().endsWith(bookings+"/"+own.body.id)&&r.request().method()==="DELETE");
  await page.locator(".popover:visible .btn-danger").click();assert.equal((await response).status(),204);
  await page.reload();await settleCalendar(page);assert.equal((await currentBookings(page)).length,0);
}
module.exports = async () => {
  const browser = await chromium.launch({
    headless: true,
    chromiumSandbox: true,
  });
  const context = await browser.newContext({
    serviceWorkers: "block",
    viewport: viewports[size],
  });
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  const unexpected = [],
    errors = [],
    responses = [];
  // Defense in depth. Actual network denial is separately proved without interceptors by tooling.
  await context.route("**/*", (route) => {
    const url = new URL(route.request().url());
    if (url.origin !== origin) {
      unexpected.push(url.origin);
      return route.abort("blockedbyclient");
    }
    return route.continue();
  });
  page.on("pageerror", (error) => errors.push(error.stack || error.message));
  page.on("response", (response) => {
    const url = new URL(response.url());
    if (url.origin === origin)
      responses.push({
        path: url.pathname,
        method: response.request().method(),
        status: response.status(),
      });
  });
  await page.exposeFunction("__hmsVueError", (message) => errors.push(message));
  await context.routeWebSocket(/.*/, (socket) => {
    unexpected.push(new URL(socket.url()).origin);
    socket.close({ code: 1008, reason: "Test denies websocket delivery" });
  });
  try {
    await { visibility, booking, grant, denial, maintainer }[scenario](page);
    assert.deepEqual(unexpected, [], "Unexpected off-origin request");
    assert.deepEqual(errors, [], "Uncaught browser error");
    const vue = await page.evaluate(() => window.__hmsBrowser?.errors || []);
    assert.deepEqual(vue, [], "Vue runtime error");
    return {
      scenario,
      viewport: size,
      status: "passed",
      browser: browser.version(),
      boundary:
        "actual local kernel/routes/database and public libraries; Echo recorded",
    };
  } catch (error) {
    const diagnostics = await page
      .evaluate(() => ({
        path: location.pathname,
        calendar: !!document.querySelector(".fc-timegrid"),
        events: document.querySelectorAll(".fc-timegrid-event").length,
        popovers: [...document.querySelectorAll(".popover")].map((el) => ({
          text: el.textContent,
          buttons: [...el.querySelectorAll("a,button")].map((b) => b.className),
        })),
        vueErrors: window.__hmsBrowser?.errors || [],
        pointer: window.__hmsBrowser?.pointer,
      }))
      .catch(() => ({ unavailable: true }));
    error.message +=
      "\nDiagnostics: " +
      JSON.stringify({ diagnostics, responses: responses.slice(-12) });
    throw error;
  } finally {
    await context.close();
    await browser.close();
  }
};
