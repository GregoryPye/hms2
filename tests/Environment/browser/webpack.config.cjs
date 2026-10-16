const path = require("node:path");
const webpack = require("webpack");
const VueLoaderPlugin = require("vue-loader/lib/plugin");
module.exports = {
  mode: "development",
  devtool: false,
  entry: "/tools/entry.cjs",
  output: { path: "/tools/assets/js", filename: "app.js" },
  resolve: { alias: { vue$: "vue/dist/vue.esm.js" } },
  module: {
    rules: [
      { test: /\.vue$/, loader: "vue-loader" },
      { test: /\.css$/, use: ["style-loader", "css-loader"] },
      {
        test: /\.scss$/,
        use: [
          "style-loader",
          "css-loader",
          {
            loader: "sass-loader",
            options: {
              additionalData:
                '@import "/tools/style/_variables.scss"; @import "bootstrap/scss/functions"; @import "bootstrap/scss/variables"; @import "bootstrap/scss/mixins";',
            },
          },
        ],
      },
    ],
  },
  plugins: [
    new VueLoaderPlugin(),
    new webpack.ProvidePlugin({
      $: "jquery",
      jQuery: "jquery",
      Vue: ["vue", "default"],
    }),
  ],
  performance: { hints: false },
};
