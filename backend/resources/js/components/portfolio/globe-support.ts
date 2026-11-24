/** True when the browser can draw WebGL (otherwise the portfolio map shows tiles). Kept out of the globe chunk so checking it does not load three.js. */
export function canUseWebGL(): boolean {
  try {
    const c = document.createElement("canvas")
    return !!(window.WebGLRenderingContext && (c.getContext("webgl2") || c.getContext("webgl")))
  } catch {
    return false
  }
}
