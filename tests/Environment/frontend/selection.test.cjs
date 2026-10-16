const { mount, flush, state, $, Vue } = require("./support/setup.cjs");
const Select = require("./source/SelectTwo.vue").default;
const Member = require("./source/MemberSelectTwo.vue").default;
const Search = require("./source/MemberSearch.vue").default;
Vue.component("select-two", Select);
Vue.component("member-select-two", Member);
const user = (extra = {}) => ({
  id: 11,
  accountId: 22,
  fullname: "Synthetic Member",
  username: "synthetic",
  email: "member@example.invalid",
  paymentRef: "TEST-ONLY",
  ...extra,
});
test("selector renders form flags, slots and plugin settings", async () => {
  const w = mount(
    Select,
    {
      id: "synthetic-id",
      name: "members",
      disabled: true,
      required: true,
      invalid: true,
      placeholder: "Choose",
      settings: { width: "90%" },
      options: [{ id: "11", text: "Synthetic" }],
    },
    { slots: { prepend: "<span>Before</span>", append: "<span>After</span>" } },
  );
  await flush();
  expect(w.classes()).toEqual(
    expect.arrayContaining(["input-group", "is-invalid"]),
  );
  expect(w.find("select").attributes()).toMatchObject({
    id: "synthetic-id",
    name: "members",
    disabled: "disabled",
    required: "required",
  });
  expect(w.findAll("div.d-none.is-invalid")).toHaveLength(1);
  expect(state.plugins[0].args[0]).toMatchObject({
    theme: "bootstrap",
    placeholder: "Choose",
    width: "90%",
    data: [{ id: "11", text: "Synthetic" }],
  });
  expect(state.plugins[0].args[0].dropdownParent).toBe(w.element);
});
test("selector watchers replace options and scalar/array values; defaults clear", async () => {
  const w = mount(Select, {
    options: [
      { id: "11", text: "One" },
      { id: "12", text: "Two" },
    ],
    value: "11",
  });
  await flush();
  // The plugin double does not populate DOM options; provide ordinary select options explicitly.
  w.find("select").element.innerHTML =
    '<option value="11">One</option><option value="12">Two</option>';
  await w.setProps({ value: "12" });
  expect($(w.find("select").element).val()).toBe("12");
  w.find("select").element.multiple = true;
  await w.setProps({ value: ["11", "12"] });
  expect($(w.find("select").element).val()).toEqual(["11", "12"]);
  await w.setProps({ options: [{ id: "13", text: "Three" }] });
  expect(state.plugins.at(-1).args[0].data).toEqual([
    { id: "13", text: "Three" },
  ]);
  w.vm.setOption();
  expect(state.plugins.at(-1).args[0].data).toEqual([]);
});
test.each(["select", "unselect"])(
  "selector forwards %s data and value",
  async (name) => {
    const w = mount(Select);
    await flush();
    const select = $(w.find("select").element);
    select.append('<option value="11">Synthetic</option>').val("11");
    select.trigger({ type: "select2:" + name, params: { data: { id: 11 } } });
    expect(w.emitted("change")).toEqual([["11"]]);
    expect(w.emitted("select")).toEqual([[{ id: 11 }]]);
  },
);
test.each(["closing", "close", "opening", "open", "clearing", "clear"])(
  "selector forwards %s lifecycle event",
  async (name) => {
    const w = mount(Select);
    await flush();
    $(w.find("select").element).trigger("select2:" + name);
    expect(w.emitted(name)).toHaveLength(1);
  },
);
test("selector destroys plugin exactly once", async () => {
  const w = mount(Select);
  await flush();
  w.destroy();
  expect(
    state.plugins.filter(
      (p) => p.name === "select2" && p.args[0] === "destroy",
    ),
  ).toHaveLength(1);
});
test.each([false, true])(
  "member selector maps query and account IDs (%s)",
  async (returnAccountId) => {
    const w = mount(Member, {
      returnAccountId,
      withAccount: returnAccountId,
      currentOnly: returnAccountId,
      disabled: true,
      invalid: true,
    });
    await flush();
    const settings = w.vm.mySettings;
    expect(settings).toMatchObject({ minimumInputLength: 1, width: "100%" });
    expect(settings.ajax).toMatchObject({
      url: "/api/search/users",
      delay: 250,
      cache: true,
      dataType: "json",
    });
    expect(settings.ajax.data({ term: "Synthetic", page: 2 })).toEqual({
      q: "Synthetic",
      page: 2,
      withAccount: +returnAccountId,
      currentOnly: +returnAccountId,
    });
    const params = {},
      result = settings.ajax.processResults(
        { data: [user()], per_page: 1, total: 2 },
        params,
      );
    expect(result.results[0]).toMatchObject({
      id: returnAccountId ? 22 : 11,
      text: "Synthetic Member",
    });
    expect(result.pagination.more).toBe(true);
    expect(params.page).toBe(1);
    expect(
      settings.ajax.processResults(
        { data: [], per_page: 1, total: 2 },
        { page: 2 },
      ).pagination.more,
    ).toBe(false);
    expect(w.findComponent(Select).props()).toMatchObject({
      name: "user_id",
      disabled: true,
      invalid: true,
    });
  },
);
test("member selector forwards changes/selections and clears local value", async () => {
  const w = mount(Member);
  await flush();
  w.vm.myChangeEvent("11");
  w.vm.mySelectEvent({ id: 11 });
  expect(w.emitted("change")).toEqual([["11"]]);
  expect(w.emitted("select")).toEqual([[{ id: 11 }]]);
  await w.setData({ value: "11" });
  w.vm.clear();
  await flush();
  expect(w.vm.value).toBe("");
});
test.each([false, true])(
  "member result formats optional detail (%s)",
  async (details) => {
    const w = mount(Member);
    await flush();
    const result = w.vm.formatUser(
      user(
        details
          ? {
              address1: "Synthetic street",
              addressPostcode: "TEST",
              discordUsername: "synthetic-chat",
            }
          : {},
      ),
    );
    expect(result.find(".fullname").text()).toBe("Synthetic Member");
    expect(result.find(".address")).toHaveLength(+details);
    expect(result.find(".discordUsername")).toHaveLength(+details);
    expect(w.vm.formatUser({ loading: true, text: "Loading" })).toBe("Loading");
    expect(w.vm.formatUserSelection({ text: "Selected" })).toBe("Selected");
  },
);
test("DEF-16 member result treats API member fields as text", async () => {
  const w = mount(Member);
  await flush();
  const result = w.vm.formatUser(user({ fullname: "<em>synthetic</em>" }));
  expect(result.find(".fullname").text()).toBe("<em>synthetic</em>");
  expect(result.find("em")).toHaveLength(0);
});
test("search forwards filters and submits updated GET route after selector event", async () => {
  const w = mount(Search, {
    action: "users.admin.show",
    placeholder: "Find synthetic member",
    withAccount: true,
    returnAccountId: true,
    currentOnly: true,
  });
  await flush();
  expect(w.attributes("method")).toBe("GET");
  expect(w.findComponent(Member).props()).toMatchObject({
    name: null,
    placeholder: "Find synthetic member",
    withAccount: true,
    returnAccountId: true,
    currentOnly: true,
  });
  w.findComponent(Member).vm.$emit("change", "22");
  await flush();
  expect(w.attributes("action")).toBe("/admin/users/22");
  expect(HTMLFormElement.prototype.submit).toHaveBeenCalledTimes(1);
  expect(w.find("select").attributes("name")).toBeUndefined();
});
test("member selector propagates the actual child plugin selection through v-model", async () => {
  const w = mount(Member);
  await flush();
  const select = $(w.find("select").element);
  select.append('<option value="11">Synthetic</option>').val("11");
  select.trigger({ type: "select2:select", params: { data: { id: 11 } } });
  await flush();
  expect(w.vm.value).toBe("11");
  expect(w.emitted("change")).toEqual([["11"]]);
  expect(w.emitted("select")).toEqual([[{ id: 11 }]]);
});
