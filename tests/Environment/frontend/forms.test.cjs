const { mount, flush, state, $, Vue, axios } = require("./support/setup.cjs");
const Select = require("./source/SelectTwo.vue").default,
  Member = require("./source/MemberSelectTwo.vue").default;
Vue.component("select-two", Select);
Vue.component("member-select-two", Member);
const Grant = require("./source/Tools/ToolGrantModal.vue").default,
  Usage = require("./source/Tools/ToolUsageAddModal.vue").default;
test.each(["MAINTAINER", "INDUCTOR"])(
  "grant %s renders native PATCH form with current-member selector",
  async (grantType) => {
    const w = mount(Grant, {
      toolId: 7,
      toolName: "Synthetic tool",
      grantType,
      small: true,
      block: true,
    });
    await flush();
    const form = document.querySelector("form");
    expect(form.getAttribute("method")).toBe("POST");
    expect(form.getAttribute("action")).toBe("/tools/7/grant");
    // The recording plugin leaves option creation to this local DOM fixture.
    $(form)
      .find("select")
      .append('<option value="11">Synthetic member</option>')
      .val("11")
      .trigger({ type: "select2:select", params: { data: { id: 11 } } });
    await flush();
    expect(Object.fromEntries(new FormData(form))).toMatchObject({
      _token: "test-csrf-only",
      _method: "PATCH",
      user_id: "11",
      grantType,
    });
    expect(document.querySelector(".modal-title").textContent).toContain(
      grantType[0] + grantType.slice(1).toLowerCase(),
    );
    expect(w.findComponent(Member).props("currentOnly")).toBe(true);
    expect(document.querySelector("button").classList.contains("btn-sm")).toBe(
      true,
    );
    expect(
      document.querySelector("button").classList.contains("btn-block"),
    ).toBe(true);
    document.querySelector("button").click();
    await flush();
    expect(
      state.plugins.filter((p) => p.name === "modal").map((p) => p.args[0]),
    ).toEqual(["handleUpdate", "toggle"]);
    expect(axios.post).not.toHaveBeenCalled();
  },
);
test("grant default button size and title follow changed props", async () => {
  const w = mount(Grant, {
    toolId: 7,
    toolName: "Synthetic tool",
    grantType: "INDUCTOR",
  });
  await flush();
  expect(document.querySelector("i").classList.contains("fa-lg")).toBe(true);
  await w.setProps({ toolId: 8, grantType: "MAINTAINER" });
  expect(document.querySelector("form").getAttribute("action")).toBe(
    "/tools/8/grant",
  );
  expect(document.querySelector(".modal-title").textContent).toContain(
    "Maintainer",
  );
});
test.each([false, true])(
  "usage form serializes synthetic duration and member (%s)",
  async (small) => {
    const w = mount(Usage, {
      toolId: 7,
      toolName: "Synthetic tool",
      userId: "11",
      userName: "Synthetic member",
      small,
      block: small,
    });
    await flush();
    const form = document.querySelector("form");
    expect(form.getAttribute("method")).toBe("POST");
    expect(form.getAttribute("action")).toBe("/tools/7/free-time");
    $(form).find('[name="hours"]').val("2");
    $(form).find('[name="minutes"]').val("30");
    expect(Object.fromEntries(new FormData(form))).toEqual({
      _token: "test-csrf-only",
      user_id: "11",
      hours: "2",
      minutes: "30",
    });
    expect(document.querySelector(".modal-title").textContent).toContain(
      "Synthetic member",
    );
    document.querySelector("button").click();
    await flush();
    expect(
      state.plugins.filter((p) => p.name === "modal").map((p) => p.args[0]),
    ).toEqual(["handleUpdate", "toggle"]);
    expect(axios.post).not.toHaveBeenCalled();
  },
);
