const fs = require("node:fs");
require("webpack")(require("./webpack.config.cjs"), (error, stats) => {
  if (error || stats.hasErrors()) {
    console.error(error || stats.toString({ all: false, errors: true }));
    process.exitCode = 1;
    return;
  }
  for (const name of [
    "css/app.css",
    "css/vue.css",
    "js/manifest.js",
    "js/vendor.js",
  ]) {
    fs.mkdirSync("/tools/assets/" + name.split("/")[0], { recursive: true });
    fs.writeFileSync(
      "/tools/assets/" + name,
      "/* Public-only test entrypoint supplies selected actual component/library styles. */\n",
    );
  }
  console.log(
    stats.toString({ all: false, assets: true, timings: true, warnings: true }),
  );
});
