/*
  Sustituto mínimo de React para paper-shaders.js.
  La librería declara al cargar dos componentes de React (forwardRef y memo) que el expediente
  no usa: solo usamos su motor WebGL (ShaderMount). Con esto no hace falta descargar React de un CDN.
*/
window.React = window.React || {
  createElement: function () { return null; },
  forwardRef: function (componente) { return componente; },
  memo: function (componente) { return componente; }
};
