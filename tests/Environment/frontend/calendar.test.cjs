const {
  mount,
  flush,
  respond,
  reject,
  deferred,
  booking,
  axios,
  state,
  moment,
} = require("./support/setup.cjs");
const Calendar = require("./source/Tools/ToolCalendar.vue").default;
function props(overrides = {}) {
  return {
    toolId: 7,
    bookingLengthMax: 120,
    bookingsMax: 3,
    bookingsUrl: "/api/tools/7/bookings",
    toolRestricted: true,
    userCanBook: {
      userId: 11,
      normal: true,
      induction: true,
      maintenance: true,
      normalCurrentCount: 0,
    },
    ...overrides,
  };
}
async function tool(overrides = {}) {
  const w = mount(Calendar, props(overrides));
  await flush();
  w.vm.loading(false);
  return w;
}
const range = (
  start = "2025-02-15T13:00:00Z",
  end = "2025-02-15T14:00:00Z",
) => ({ start: new Date(start), end: new Date(end) });

test("UI-01 options wire actual callbacks, London timezone and responsive views", async () => {
  const w = await tool();
  const o = w.vm.calendarOptions;
  expect(o.timeZone).toBe("Europe/London");
  expect(o.firstDay).toBe(1);
  expect(o.select).toBe(w.vm.select);
  expect(o.eventDrop).toBe(w.vm.eventDrop);
  expect(o.eventResize).toBe(w.vm.eventResize);
  expect(w.vm.initialView).toBe("timeGridWeek");
  Object.defineProperty(document.documentElement, "clientWidth", {
    configurable: true,
    value: 400,
  });
  window.dispatchEvent(new Event("resize"));
  expect(w.vm.initialView).toBe("timeGridDay");
  expect(w.vm.calendarApi.changeView).toHaveBeenLastCalledWith("timeGridDay");
  jest.advanceTimersByTime(100);
  expect(w.vm.calendarApi.updateSize).toHaveBeenCalledTimes(1);
  expect(w.vm.calendarApi.scrollToTime).toHaveBeenCalledWith("06:00");
  jest.advanceTimersByTime(900000);
  expect(w.vm.calendarApi.refetchEvents).toHaveBeenCalledTimes(1);
});
test.each([
  ["loading", {}, range(), false],
  [
    "restricted denial",
    {
      userCanBook: {
        userId: 11,
        normal: false,
        induction: false,
        maintenance: false,
        normalCurrentCount: 0,
      },
    },
    range(),
    false,
  ],
  ["past", {}, range("2025-02-15T11:00:00Z"), false],
  [
    "too long",
    {},
    range("2025-02-15T13:00:00Z", "2025-02-15T15:01:00Z"),
    false,
  ],
  [
    "exact maximum",
    {},
    range("2025-02-15T13:00:00Z", "2025-02-15T15:00:00Z"),
    true,
  ],
  [
    "unrestricted",
    { toolRestricted: false, userCanBook: { normal: false } },
    range(),
    true,
  ],
])("UI-01 selection boundary: %s", async (name, p, r, allowed) => {
  const w = await tool(p);
  if (name === "loading") w.vm.isLoading = true;
  expect(w.vm.selectAllow(r)).toBe(allowed);
});
test.each([
  [
    "ongoing move",
    range("2025-02-15T11:30:00Z", "2025-02-15T13:00:00Z"),
    range(),
    "NORMAL",
    false,
  ],
  [
    "ongoing resize",
    range("2025-02-15T11:30:00Z", "2025-02-15T13:00:00Z"),
    range("2025-02-15T11:30:00Z", "2025-02-15T13:30:00Z"),
    "NORMAL",
    true,
  ],
  [
    "ongoing end past",
    range("2025-02-15T11:00:00Z", "2025-02-15T13:00:00Z"),
    range("2025-02-15T11:00:00Z", "2025-02-15T11:59:00Z"),
    "NORMAL",
    false,
  ],
  [
    "move into past",
    range(),
    range("2025-02-15T11:00:00Z", "2025-02-15T12:00:00Z"),
    "NORMAL",
    false,
  ],
  [
    "normal overlong",
    range(),
    range("2025-02-15T13:00:00Z", "2025-02-15T16:00:00Z"),
    "NORMAL",
    false,
  ],
  [
    "maintenance overlong",
    range(),
    range("2025-02-15T13:00:00Z", "2025-02-15T16:00:00Z"),
    "MAINTENANCE",
    true,
  ],
])(
  "UI-01 move/resize boundary: %s",
  async (name, old, drop, type, expected) => {
    const w = await tool();
    expect(w.vm.eventAllow(drop, { ...old, extendedProps: { type } })).toBe(
      expected,
    );
  },
);
test.each([
  [11, "2025-02-15T13:00:00Z", true],
  [12, "2025-02-15T13:00:00Z", false],
  [11, "2025-02-15T11:00:00Z", false],
])(
  "UI-01 cancellation click ownership %s / %s",
  async (userId, end, allowed) => {
    const w = await tool();
    const spy = jest
      .spyOn(w.vm, "setupCancelConfirmation")
      .mockImplementation(() => {});
    const info = { event: { end: new Date(end), extendedProps: { userId } } };
    w.vm.eventClick(info);
    expect(spy).toHaveBeenCalledTimes(allowed ? 1 : 0);
    w.vm.isLoading = true;
    w.vm.eventClick(info);
    expect(spy).toHaveBeenCalledTimes(allowed ? 1 : 0);
    w.vm.isLoading = false;
    w.vm.userCanBook.userId = null;
    w.vm.eventClick(info);
    expect(spy).toHaveBeenCalledTimes(allowed ? 1 : 0);
  },
);
test.each(["NORMAL", "INDUCTION", "MAINTENANCE"])(
  "UI-01 confirmation builds %s FormData with timezone offset",
  async (type) => {
    const w = await tool();
    const create = jest
      .spyOn(w.vm, "createBooking")
      .mockImplementation(() => {});
    w.vm.bookOnConfirm(
      type,
      range("2025-07-10T11:00:00Z", "2025-07-10T12:00:00Z"),
    );
    const form = create.mock.calls[0][0];
    expect(Object.fromEntries(form.entries())).toEqual({
      type,
      start: "2025-07-10T12:00:00.000+01:00",
      end: "2025-07-10T13:00:00.000+01:00",
    });
  },
);
test("UI-01 confirmation permission buttons and maximum normal count", async () => {
  const w = await tool();
  w.vm.select(range());
  let options = state.plugins
    .filter((p) => p.name === "confirmation" && typeof p.args[0] === "object")
    .at(-1).args[0];
  expect(options.buttons.filter((x) => x.value).map((x) => x.value)).toEqual([
    "NORMAL",
    "INDUCTION",
    "MAINTENANCE",
  ]);
  w.vm.userCanBook.normalCurrentCount = 3;
  w.vm.setupBookingConfirmation(range());
  options = state.plugins
    .filter((p) => p.name === "confirmation" && typeof p.args[0] === "object")
    .at(-1).args[0];
  expect(options.buttons.some((x) => x.value === "NORMAL")).toBe(false);
  expect(options.content).toContain("3 normal bookings");
  options.onCancel();
  expect(w.vm.calendarApi.unselect).toHaveBeenCalled();
  w.vm.userCanBook.induction = false;
  w.vm.userCanBook.maintenance = false;
  w.vm.userCanBook.normalCurrentCount = 0;
  w.vm.setupBookingConfirmation(range());
  options = state.plugins
    .filter((p) => p.name === "confirmation" && typeof p.args[0] === "object")
    .at(-1).args[0];
  expect(options.buttons.filter((x) => x.value).map((x) => x.value)).toEqual([
    "NORMAL",
  ]);
});
test.each([
  ["owned future", {}, true, false],
  ["owned ongoing", { start: "2025-02-15T11:00:00Z" }, false, true],
  ["other user", { userId: 12 }, false, false],
  [
    "past",
    { start: "2025-02-15T10:00:00Z", end: "2025-02-15T11:00:00Z" },
    false,
    false,
  ],
])("UI-01 event editability: %s", async (name, changes, edit, resize) => {
  const w = await tool();
  const b = w.vm.mapBookings(booking(changes));
  expect(!!b.editable).toBe(edit);
  expect(!!b.durationEditable).toBe(resize);
  expect(b.className).toBe(
    edit || resize ? "tool-normal" : "tool-normal not-editable",
  );
});
test("UI-02 range fetch passes bounds, cancels old request and suppresses cancellation errors", async () => {
  const w = await tool();
  const a = deferred("get"),
    b = deferred("get");
  const ok = jest.fn(),
    fail = jest.fn();
  w.vm.fetchBookings({ startStr: "a", endStr: "b" }, ok, fail);
  const token = axios.get.mock.calls[0][1].cancelToken;
  w.vm.fetchBookings({ startStr: "c", endStr: "d" }, ok, fail);
  expect(token.cancel).toHaveBeenCalledWith("New events range requested");
  expect(axios.get.mock.calls[1][1].params).toEqual({ start: "c", end: "d" });
  a.reject({ __CANCEL__: true });
  b.resolve({ data: [booking()] });
  await flush();
  expect(ok).toHaveBeenCalledTimes(1);
  expect(ok.mock.calls[0][0][0].editable).toBe(true);
  expect(fail).not.toHaveBeenCalled();
  expect(flash).not.toHaveBeenCalled();
});
test("UI-02 range fetch rejection invokes failure and error flash", async () => {
  const w = await tool();
  reject("get", new Error("synthetic"));
  const fail = jest.fn();
  w.vm.fetchBookings({ startStr: "a", endStr: "b" }, jest.fn(), fail);
  await flush();
  expect(fail).toHaveBeenCalledTimes(1);
  expect(flash).toHaveBeenCalledWith("Error fetching bookings", "danger");
});
test.each(["api first", "Echo first"])(
  "UI-03 creation deduplicates same ID: %s",
  async (order) => {
    const w = await tool();
    const pending = deferred("post");
    w.vm.createBooking(new FormData());
    if (order === "Echo first") w.vm.newBookingEvent({ booking: booking() });
    pending.resolve({ status: 201, data: booking() });
    await flush();
    if (order === "api first") w.vm.newBookingEvent({ booking: booking() });
    expect(w.vm.calendarApi.getEvents()).toHaveLength(1);
    expect(w.vm.userCanBook.normalCurrentCount).toBe(1);
    expect(w.vm.isLoading).toBe(false);
    expect(w.vm.calendarApi.unselect).toHaveBeenCalled();
  },
);
test.each([
  ["unexpected", null],
  ["response", { response: { status: 422, data: "synthetic", headers: {} } }],
  ["request", { request: {} }],
  ["setup", { message: "synthetic" }],
])("UI-02 create failure recovers: %s", async (name, error) => {
  const w = await tool();
  if (error) reject("post", error);
  else respond("post", { status: 200, data: {} });
  w.vm.createBooking(new FormData());
  await flush();
  expect(w.vm.calendarApi.getEvents()).toHaveLength(0);
  expect(w.vm.isLoading).toBe(false);
  expect(w.vm.userCanBook.normalCurrentCount).toBe(0);
  expect(flash).toHaveBeenCalledWith("Error creating booking", "danger");
  expect(w.vm.calendarApi.refetchEvents).toHaveBeenCalledTimes(
    name === "response" ? 1 : 0,
  );
});
test.each(["eventDrop", "eventResize"])(
  "UI-01 %s delegates actual patch with ISO times",
  async (method) => {
    const w = await tool();
    respond("patch", { status: 200 });
    const event = { id: 1, ...range() };
    const revert = jest.fn();
    w.vm[method]({ event, revert });
    await flush();
    expect(axios.patch).toHaveBeenCalledWith("/api/tools/7/bookings/1", {
      start: "2025-02-15T13:00:00.000+00:00",
      end: "2025-02-15T14:00:00.000+00:00",
    });
    expect(revert).not.toHaveBeenCalled();
    expect(w.vm.isLoading).toBe(false);
  },
);
test.each([
  ["unexpected", null],
  ["response", { response: { status: 422, data: "synthetic", headers: {} } }],
  ["request", { request: {} }],
  ["setup", { message: "synthetic" }],
])("UI-02 patch failure reverts exactly once: %s", async (name, error) => {
  const w = await tool();
  if (error) reject("patch", error);
  else respond("patch", { status: 201, data: {} });
  const revert = jest.fn();
  w.vm.patchBooking({ id: 1, ...range() }, revert);
  await flush();
  expect(revert).toHaveBeenCalledTimes(1);
  expect(w.vm.isLoading).toBe(false);
  expect(w.vm.calendarApi.refetchEvents).toHaveBeenCalledTimes(
    name === "response" ? 1 : 0,
  );
});
test.each(["api first", "Echo first"])(
  "UI-03 cancellation deduplicates: %s",
  async (order) => {
    const w = await tool();
    w.vm.newBookingEvent({ booking: booking() });
    const event = w.vm.calendarApi.getEventById(1);
    const pending = deferred("delete");
    w.vm.cancelBooking(event);
    if (order === "Echo first") w.vm.bookingCancelledEvent({ bookingId: 1 });
    pending.resolve({ status: 204 });
    await flush();
    if (order === "api first") w.vm.bookingCancelledEvent({ bookingId: 1 });
    w.vm.bookingCancelledEvent({ bookingId: 1 });
    expect(w.vm.calendarApi.getEvents()).toHaveLength(0);
    expect(w.vm.userCanBook.normalCurrentCount).toBe(0);
    expect(w.vm.isLoading).toBe(false);
  },
);
test.each([
  ["unexpected", null],
  ["response", { response: { status: 403, data: "synthetic", headers: {} } }],
  ["request", { request: {} }],
  ["setup", { message: "synthetic" }],
])("UI-02 cancellation failure retains event: %s", async (name, error) => {
  const w = await tool();
  w.vm.newBookingEvent({ booking: booking() });
  if (error) reject("delete", error);
  else respond("delete", { status: 200 });
  w.vm.cancelBooking(w.vm.calendarApi.getEventById(1));
  await flush();
  expect(w.vm.calendarApi.getEvents()).toHaveLength(1);
  expect(w.vm.userCanBook.normalCurrentCount).toBe(1);
  expect(w.vm.isLoading).toBe(false);
});
test("UI-03 changed broadcasts replace, do not duplicate or change count", async () => {
  const w = await tool();
  w.vm.newBookingEvent({ booking: booking() });
  w.vm.bookingChangedEvent({
    booking: booking({ end: "2025-02-15T14:30:00Z" }),
  });
  w.vm.bookingChangedEvent({
    booking: booking({ end: "2025-02-15T14:30:00Z" }),
  });
  expect(w.vm.calendarApi.getEvents()).toHaveLength(1);
  expect(w.vm.calendarApi.getEventById(1).end.toISOString()).toBe(
    "2025-02-15T14:30:00.000Z",
  );
  expect(w.vm.userCanBook.normalCurrentCount).toBe(1);
});
test("UI-03 DEF-03 other-user creation must preserve current-user count", async () => {
  const w = await tool();
  w.vm.newBookingEvent({ booking: booking({ userId: 12 }) });
  expect(w.vm.calendarApi.getEvents()).toHaveLength(1);
  expect(w.vm.userCanBook.normalCurrentCount).toBe(0);
});
test("UI-03 DEF-03 other-user cancellation must preserve current-user count", async () => {
  const w = await tool();
  w.vm.calendarApi.addEvent(booking({ userId: 12 }), "bookings");
  w.vm.bookingCancelledEvent({ bookingId: 1 });
  expect(w.vm.calendarApi.getEvents()).toHaveLength(0);
  expect(w.vm.userCanBook.normalCurrentCount).toBe(0);
});
test("UI-02 DEF-14 repeated loading true must keep active overlay", async () => {
  const w = await tool();
  w.vm.loading(true);
  const loader = state.loaders.at(-1);
  w.vm.loading(true);
  expect(w.vm.isLoading).toBe(true);
  expect(loader.hide).not.toHaveBeenCalled();
  expect(w.vm.loader).toBe(loader);
});
test("UI-04 destroy removes interval, resize handler and Echo channel", async () => {
  const w = await tool();
  jest.advanceTimersByTime(100);
  const api = w.vm.calendarApi;
  api.changeView.mockClear();
  api.refetchEvents.mockClear();
  w.destroy();
  jest.advanceTimersByTime(900000);
  window.dispatchEvent(new Event("resize"));
  expect(api.changeView).not.toHaveBeenCalled();
  expect(api.refetchEvents).not.toHaveBeenCalled();
  expect(Echo.leave).toHaveBeenCalledWith("tools.7.bookings");
});
test("UI-04 DEF-15 destroy must cancel pending layout timeout", async () => {
  const w = await tool();
  const api = w.vm.calendarApi;
  w.destroy();
  jest.advanceTimersByTime(100);
  expect(api.updateSize).not.toHaveBeenCalled();
});
test("UI-01 default capabilities preserve existing normal-booking option", async () => {
  const w = await tool({ userCanBook: undefined });
  expect(w.vm.userCanBook.userId).toBeNull();
  expect(w.vm.selectAllow(range())).toBe(true);
  w.vm.setupBookingConfirmation(range());
  const options = state.plugins
    .filter((p) => p.name === "confirmation" && typeof p.args[0] === "object")
    .at(-1).args[0];
  expect(options.buttons.filter((b) => b.value).map((b) => b.value)).toEqual(["NORMAL"]);
});
test("UI-01 confirmation callbacks create selected type and remove popovers", async () => {
  const w = await tool(),
    create = jest.spyOn(w.vm, "bookOnConfirm").mockImplementation(() => {});
  w.vm.setupBookingConfirmation(range());
  const options = state.plugins.find((p) => p.name === "confirmation").args[0];
  options.onConfirm("NORMAL");
  expect(create).toHaveBeenCalledWith("NORMAL", range());
  document.body.insertAdjacentHTML("beforeend", '<div class="popover"></div>');
  w.vm.calendarOptions.unselect();
  expect(document.querySelector(".popover")).toBeNull();
});
test("UI-01 cancellation confirmation callbacks unmark selection and cancel exactly once", async () => {
  const w = await tool(),
    el = document.createElement("a");
  document.body.appendChild(el);
  const event = w.vm.calendarApi.addEvent(booking()),
    cancel = jest.spyOn(w.vm, "cancelBooking").mockImplementation(() => {});
  w.vm.setupCancelConfirmation({ el, event });
  const options = state.plugins.find((p) => p.name === "confirmation").args[0];
  expect(el.classList.contains("booking-selected")).toBe(true);
  options.onCancel();
  expect(el.classList.contains("booking-selected")).toBe(false);
  expect(cancel).not.toHaveBeenCalled();
  el.classList.add("booking-selected");
  options.onConfirm();
  expect(cancel).toHaveBeenCalledTimes(1);
  expect(cancel).toHaveBeenCalledWith(event);
  expect(el.classList.contains("booking-selected")).toBe(false);
});
test("UI-01 move rejects interaction while loading", async () => {
  const w = await tool();
  w.vm.isLoading = true;
  expect(
    w.vm.eventAllow(range(), { ...range(), extendedProps: { type: "NORMAL" } }),
  ).toBe(false);
});
test.each(["INDUCTION", "MAINTENANCE"])(
  "UI-03 non-normal %s create/change/cancel preserves normal count",
  async (type) => {
    const w = await tool();
    respond("post", { status: 201, data: booking({ type }) });
    w.vm.createBooking(new FormData());
    await flush();
    expect(w.vm.userCanBook.normalCurrentCount).toBe(0);
    w.vm.bookingChangedEvent({ booking: booking({ id: 2, type }) });
    expect(w.vm.calendarApi.getEvents()).toHaveLength(2);
    w.vm.bookingCancelledEvent({ bookingId: 2 });
    expect(w.vm.userCanBook.normalCurrentCount).toBe(0);
    respond("delete", { status: 204 });
    w.vm.cancelBooking(w.vm.calendarApi.getEventById(1));
    await flush();
    expect(w.vm.userCanBook.normalCurrentCount).toBe(0);
    expect(w.vm.calendarApi.getEvents()).toHaveLength(0);
  },
);
