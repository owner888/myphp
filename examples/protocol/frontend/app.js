// A module, loaded with a relative URL from myphp://localhost/.
where.textContent = location.href;
const names = await window.myphp.call("Shapes.names"); // the app's own page may call PHP
const draw = () => {
  shapes.innerHTML = names.map((n) => `<img alt="${n}" src="shapes://localhost/${n}.svg?hue=${hue.value}">`).join("");
};
hue.oninput = draw;
draw();
ticks.onclick = async () => {
  out.textContent = "";
  const res = await fetch("shapes://localhost/ticks"); // another origin: the scheme's CORS headers let the app's page read it
  const reader = res.body.getReader();
  const text = new TextDecoder();
  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    out.textContent += text.decode(value);
  }
};
