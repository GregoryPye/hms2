const vue = require("@vue/vue2-jest");
// vue2-jest passes Jest's instrument flag into its inner babel-jest transform.
// Instrumenting that script and then the assembled SFC overwrites coverage for
// the same filename. Let Jest instrument the assembled component exactly once.
module.exports = {
  process(source, filename, options) {
    return vue.process(source, filename, { ...options, instrument: false });
  },
  getCacheKey: vue.getCacheKey,
};
