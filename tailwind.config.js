module.exports = {
  content: ["./**/*.php"],
  theme: {
    extend: {},
  },
  plugins: [require("daisyui")],
  daisyui: {
    themes: ["dark", "light", "dracula"],
    darkTheme: "dark",
  },
};
