// User-approved public-only tools entrypoint, never the production bootstrap/app bundle.
const Vue = require("vue").default;
window.Vue = Vue;
window.$ = window.jQuery = require("jquery");
window.Popper = require("popper.js").default;
require("bootstrap");
require("bootstrap-confirmation2");
require("select2/dist/js/select2.full");
window.axios = require("axios").default;
window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
$.ajaxSetup({
  headers: {
    "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]').content,
  },
});
Vue.use(require("vue-unique-id").default);
window.events = new Vue();
window.flash = (message, level = "success") =>
  window.events.$emit("flash", message, level);
// Echo delivery is an explicitly recorded boundary. No Pusher client/socket or production key.
const subscriptions = [];
window.Echo = {
  channel(name) {
    return {
      listen(event, callback) {
        subscriptions.push({ name, event, callback });
        return this;
      },
    };
  },
  leave(name) {
    for (let i = subscriptions.length - 1; i >= 0; i--)
      if (subscriptions[i].name === name) subscriptions.splice(i, 1);
  },
};
Vue.mixin({
  methods: {
    route(name, id) {
      const routes = {
        "api.tools.bookings.index": (id) => "/api/tools/" + id + "/bookings",
        "tools.add-free-time": (id) => "/tools/" + id + "/free-time",
        "users.admin.show": (id) => "/admin/users/" + id,
      };
      if (!routes[name]) throw new Error("Unexpected tools route " + name);
      return routes[name](id);
    },
  },
});
for (const name of [
  "ToolCalendar",
  "BookingCalendarList",
  "ToolGrantModal",
  "ToolUsageAddModal",
])
  Vue.component(name, require("./source/Tools/" + name + ".vue").default);
for (const name of ["MemberSearch", "MemberSelectTwo", "SelectTwo", "Flash"])
  Vue.component(name, require("./source/" + name + ".vue").default);
require("./theme.scss");
require("select2/dist/css/select2.css");
require("select2-theme-bootstrap4/dist/select2-bootstrap.css");
require("vue-loading-overlay/dist/vue-loading.css");
Vue.config.productionTip = false;
Vue.config.devtools = false;
const errors = [];
Vue.config.errorHandler = (error) => {
  errors.push(error.message);
  if (window.__hmsVueError) window.__hmsVueError(error.message);
};
// Expose only the selected real component tree for date navigation and diagnostics.
// Journeys create/move/resize/cancel through actual DOM/plugin interaction, not component methods.
window.__hmsBrowser = { errors, subscriptions, app: new Vue({ el: "#app" }) };
