module.exports = {
  name: "FullCalendarPort",
  props: ["options"],
  render(h) {
    return h("div", { attrs: { "data-calendar-port": "" } });
  },
  methods: {
    getApi() {
      return global.__ports.makeCalendar();
    },
  },
};
