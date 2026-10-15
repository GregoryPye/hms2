const { mount } = require('@vue/test-utils');
const compiler = require('vue-template-compiler');
const fs = require('fs');

test('Vue 2 DOM mounting and cleanup work', async () => {
  const wrapper = mount({ template: '<button @click="n++">{{ n }}</button>', data: () => ({ n: 0 }) });
  await wrapper.trigger('click');
  expect(wrapper.text()).toBe('1');
  wrapper.destroy();
});

test('the unchanged HMS tool-grant template compiles', () => {
  const source = fs.readFileSync('/tools/ToolGrantModal.vue', 'utf8');
  const template = compiler.parseComponent(source).template.content;
  expect(compiler.compile(template).errors).toEqual([]);
});
