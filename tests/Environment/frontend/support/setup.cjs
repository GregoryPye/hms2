const Vue = require("vue");
const $ = require("jquery");
const moment = require("moment-timezone");
const wrappers = [];
const state = {
  queues: {},
  calendars: [],
  plugins: [],
  listeners: new Map(),
  loaders: [],
  vueErrors: [],
  unexpected: [],
};
function unexpected(name) {
  state.unexpected.push(name);
  throw new Error("Unexpected external work: " + name);
}
function makeCalendar() {
  const events = [];
  const api = {
    events,
    getEventById: jest.fn((id) =>
      events.find((event) => String(event.id) === String(id)),
    ),
    getEvents: jest.fn(() => [...events]),
  };
  api.addEvent = jest.fn((booking, source) => {
    const event = {
      ...booking,
      start: new Date(booking.start),
      end: new Date(booking.end),
      extendedProps: {
        type: booking.type,
        userId: booking.userId,
        toolId: booking.toolId,
      },
      remove: jest.fn(() => {
        const index = events.indexOf(event);
        if (index >= 0) events.splice(index, 1);
      }),
    };
    events.push(event);
    return event;
  });
  for (const name of [
    "unselect",
    "refetchEvents",
    "updateSize",
    "scrollToTime",
    "changeView",
  ])
    api[name] = jest.fn();
  state.calendars.push(api);
  return api;
}
function request(method, ...args) {
  const action = state.queues[method].shift();
  if (!action) return unexpected("axios." + method);
  return action(...args);
}
const axios = {};
for (const method of ["get", "post", "patch", "delete"])
  axios[method] = jest.fn((...args) => request(method, ...args));
axios.CancelToken = class {
  constructor(executor) {
    this.cancel = jest.fn();
    executor(this.cancel);
  }
};
axios.isCancel = (value) => !!value?.__CANCEL__;
function recordPlugin(name, elements, args) {
  state.plugins.push({ name, elements: elements.toArray(), args });
}
for (const name of ["modal", "confirmation", "select2"])
  $.fn[name] = function (...args) {
    recordPlugin(name, this, args);
    return this;
  };
$.ajax = () => unexpected("jQuery.ajax");
global.Vue = Vue;
global.$ = $;
global.axios = axios;
global.flash = jest.fn();
global.Echo = {
  channel: jest.fn((name) => ({
    listen(event, callback) {
      state.listeners.set(name + ":" + event, callback);
      return this;
    },
  })),
  leave: jest.fn(),
};
Vue.config.productionTip = false;
Vue.config.devtools = false;
Vue.config.errorHandler = (error) => {
  state.vueErrors.push(error.message);
};
Vue.mixin({
  methods: {
    $id(name) {
      return "test-" + this._uid + "-" + name;
    },
    route(name, value) {
      const routes = {
        "tools.add-free-time": (id) => "/tools/" + id + "/free-time",
        "api.tools.bookings.index": (id) => "/api/tools/" + id + "/bookings",
        "users.admin.show": (id) => "/admin/users/" + id,
      };
      if (!routes[name]) throw new Error("Unexpected route: " + name);
      return routes[name](value);
    },
  },
});
Vue.prototype.$loading = {
  show: jest.fn(() => {
    const loader = { hide: jest.fn() };
    state.loaders.push(loader);
    return loader;
  }),
};
global.__ports = { state, makeCalendar };
global.fetch = () => unexpected("fetch");
global.XMLHttpRequest = class {
  constructor() {
    unexpected("XMLHttpRequest");
  }
};
global.WebSocket = class {
  constructor() {
    unexpected("WebSocket");
  }
};
navigator.sendBeacon = () => unexpected("sendBeacon");
HTMLFormElement.prototype.submit = jest.fn();
beforeEach(() => {
  jest.useFakeTimers();
  jest.setSystemTime(new Date("2025-02-15T12:00:00Z"));
  moment.tz.setDefault("Europe/London");
  document.body.innerHTML = "";
  document.head.innerHTML = '<meta name="csrf-token" content="test-csrf-only">';
  state.queues = { get: [], post: [], patch: [], delete: [] };
  state.calendars.length = 0;
  state.plugins.length = 0;
  state.listeners.clear();
  state.loaders.length = 0;
  state.vueErrors.length = 0;
  state.unexpected.length = 0;
  Object.defineProperty(document.documentElement, "clientWidth", {
    configurable: true,
    value: 1024,
  });
  jest.spyOn(console, "log").mockImplementation(() => {});
  jest.spyOn(console, "error").mockImplementation(() => {});
});
afterEach(() => {
  for (const wrapper of wrappers.splice(0))
    if (!wrapper.vm._isDestroyed) wrapper.destroy();
  // Clear only after individual cleanup contracts have had a chance to assert pending work.
  jest.clearAllTimers();
  jest.useRealTimers();
  jest.restoreAllMocks();
  moment.tz.setDefault();
  document.body.innerHTML = "";
  document.head.innerHTML = "";
  for (const [method, queue] of Object.entries(state.queues))
    expect(queue).toHaveLength(0);
  expect(state.vueErrors).toEqual([]);
  expect(state.unexpected).toEqual([]);
});
module.exports = {
  Vue,
  $,
  moment,
  axios,
  state,
  mount(component, propsData = {}, options = {}) {
    const { mount } = require("@vue/test-utils");
    const wrapper = mount(component, {
      propsData,
      attachTo: document.body,
      ...options,
    });
    wrappers.push(wrapper);
    return wrapper;
  },
  async flush() {
    for (let i = 0; i < 8; i++) {
      await Promise.resolve();
      await Vue.nextTick();
    }
  },
  respond(method, response) {
    state.queues[method].push(() => Promise.resolve(response));
  },
  reject(method, error) {
    state.queues[method].push(() => Promise.reject(error));
  },
  deferred(method) {
    let resolve, reject;
    const promise = new Promise((a, b) => {
      resolve = a;
      reject = b;
    });
    state.queues[method].push(() => promise);
    return { promise, resolve, reject };
  },
  booking(overrides = {}) {
    return {
      id: 1,
      start: "2025-02-15T13:00:00Z",
      end: "2025-02-15T14:00:00Z",
      type: "NORMAL",
      toolId: 7,
      toolDisplayName: "Synthetic tool",
      userId: 11,
      title: "Synthetic member",
      ...overrides,
    };
  },
};
