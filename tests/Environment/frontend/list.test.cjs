const {
  mount,
  flush,
  state,
  $,
  respond,
  reject,
  booking,
  axios,
} = require("./support/setup.cjs");
const List = require("./source/Tools/BookingCalendarList.vue").default;
async function list(extra = {}) {
  const w = mount(List, {
    userId: 11,
    initialBookings: [],
    toolIds: [7, 8],
    ...extra,
  });
  await flush();
  return w;
}
test("list options and seven-day London range use actual mapped initial bookings", async () => {
  const w = await list({ initialBookings: [booking()] });
  const o = w.vm.calendarOptions;
  expect(o.timeZone).toBe("Europe/London");
  expect(o.initialView).toBe("bookingList");
  expect(o.headerToolbar).toBe(false);
  expect(o.eventSources[0].events[0]).toMatchObject({
    title: "Synthetic tool",
    className: "tool-list-normal",
    editable: true,
  });
  expect(+o.visibleRange.start).toBe(Date.parse("2025-02-15T00:00:00Z"));
  expect(+o.visibleRange.end).toBe(Date.parse("2025-02-22T12:00:00Z"));
  expect(state.listeners.size).toBe(6);
  expect(w.vm.isLoading).toBe(false);
});
test.each([
  ["future", {}, { editable: true }],
  ["ongoing", { start: "2025-02-15T11:30:00Z" }, { durationEditable: true }],
  [
    "past",
    { start: "2025-02-15T10:00:00Z", end: "2025-02-15T11:00:00Z" },
    { className: "tool-list-normal not-editable" },
  ],
])("list maps %s editability", async (_name, changes, expected) => {
  const w = await list();
  expect(w.vm.mapBookings(booking(changes))).toMatchObject(expected);
});
test.each(["Tools.NewBooking", "Tools.BookingChanged"])(
  "list ignores another member in %s",
  async (name) => {
    const w = await list();
    state.listeners.get("tools.7.bookings:" + name)({
      booking: booking({ userId: 99 }),
    });
    expect(w.vm.calendarApi.getEvents()).toHaveLength(0);
  },
);
test("list handles duplicate create, replace and cancellation broadcasts", async () => {
  const w = await list(),
    a = w.vm.calendarApi,
    send = (name, payload) =>
      state.listeners.get("tools.7.bookings:Tools." + name)(payload);
  send("NewBooking", { booking: booking() });
  send("NewBooking", { booking: booking() });
  expect(a.addEvent).toHaveBeenCalledTimes(1);
  const old = a.getEventById(1);
  send("BookingChanged", {
    booking: booking({ toolDisplayName: "Updated synthetic tool" }),
  });
  expect(old.remove).toHaveBeenCalledTimes(1);
  expect(a.getEventById(1).title).toBe("Updated synthetic tool");
  send("BookingChanged", { booking: booking({ id: 2 }) });
  expect(a.getEvents()).toHaveLength(2);
  send("BookingCancelled", { bookingId: 1 });
  send("BookingCancelled", { bookingId: 1 });
  send("BookingCancelled", { bookingId: 2 });
  expect(a.getEvents()).toHaveLength(0);
});
test("minute timer removes expired bookings and preserves future bookings", async () => {
  const w = await list(),
    a = w.vm.calendarApi;
  const past = a.addEvent(booking({ end: "2025-02-15T11:59:00Z" }));
  a.addEvent(booking({ id: 2 }));
  jest.advanceTimersByTime(60000);
  expect(past.remove).toHaveBeenCalledTimes(1);
  expect(a.getEvents().map((e) => e.id)).toEqual([2]);
  expect(a.updateSize).toHaveBeenCalledTimes(1);
});
test.each([true, false])(
  "list optional card removal %s",
  async (removeCardClass) => {
    const w = await list({ removeCardClass }),
      el = $('<div class="card fc-list-view unrelated">')[0];
    w.vm.calendarOptions.viewDidMount({ el });
    expect($(el).hasClass("card")).toBe(!removeCardClass);
    expect($(el).hasClass("unrelated")).toBe(true);
  },
);
test("list click guards loading and expired events, then confirmation cancellation", async () => {
  const w = await list(),
    el = $("<a>")[0],
    event = w.vm.calendarApi.addEvent(booking());
  w.vm.isLoading = true;
  w.vm.eventClick({ el, event });
  expect(state.plugins).toHaveLength(0);
  w.vm.isLoading = false;
  w.vm.eventClick({
    el,
    event: { ...event, end: new Date("2025-02-15T11:00:00Z") },
  });
  expect(state.plugins).toHaveLength(0);
  w.vm.eventClick({ el, event });
  const options = state.plugins.find((p) => p.name === "confirmation").args[0];
  expect($(el).hasClass("booking-selected")).toBe(true);
  options.onCancel();
  expect($(el).hasClass("booking-selected")).toBe(false);
  respond("delete", { status: 204 });
  options.onConfirm();
  await flush();
  expect(axios.delete).toHaveBeenCalledWith("/api/tools/7/bookings/1");
  expect(event.remove).toHaveBeenCalledTimes(1);
  document.body.insertAdjacentHTML("beforeend", '<div class="popover"></div>');
  w.vm.calendarOptions.unselect();
  expect($(".popover")).toHaveLength(0);
});
test.each(["status", "response", "request", "setup"])(
  "list cancel %s failure retains booking and recovers loading",
  async (kind) => {
    const w = await list(),
      event = w.vm.calendarApi.addEvent(booking());
    if (kind === "status") respond("delete", { status: 200 });
    else
      reject(
        "delete",
        kind === "response"
          ? { response: { status: 403 } }
          : kind === "request"
            ? { request: {} }
            : new Error("synthetic"),
      );
    w.vm.cancelBooking(event);
    expect(w.vm.isLoading).toBe(true);
    await flush();
    expect(event.remove).not.toHaveBeenCalled();
    expect(w.vm.isLoading).toBe(false);
    expect(flash).toHaveBeenCalledWith("Error cancelling booking", "danger");
    expect(state.loaders[0].hide).toHaveBeenCalledTimes(1);
  },
);
test("DEF-14 list repeated loading callbacks retain visible overlay until false", async () => {
  const w = await list();
  w.vm.loading(true);
  w.vm.loading(true);
  expect(state.loaders[0].hide).not.toHaveBeenCalled();
  w.vm.loading(false);
  expect(state.loaders[0].hide).toHaveBeenCalledTimes(1);
});
test("list destroys minute timer and Echo subscriptions", async () => {
  const w = await list();
  jest.advanceTimersByTime(100);
  const check = jest.spyOn(w.vm, "checkBookings");
  w.destroy();
  jest.advanceTimersByTime(120000);
  expect(check).not.toHaveBeenCalled();
  expect(Echo.leave.mock.calls.map((c) => c[0])).toEqual([
    "tools.7.bookings",
    "tools.8.bookings",
  ]);
  expect(jest.getTimerCount()).toBe(0);
});
test("DEF-15 list destruction cancels delayed layout callback", async () => {
  const w = await list(),
    api = w.vm.calendarApi;
  w.destroy();
  jest.advanceTimersByTime(100);
  expect(api.updateSize).not.toHaveBeenCalled();
});
