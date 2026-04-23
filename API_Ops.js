(() => {
  async function parseJson(response) {
    const payload = await response.json();

    if (!response.ok || !payload.ok) {
      throw new Error(payload.message || 'Request failed.');
    }

    return payload;
  }

  async function searchMovies(query) {
    const response = await fetch(`/API_Ops.php?q=${encodeURIComponent(query)}`);
    return parseJson(response);
  }

  window.MovieApiOps = {
    searchMovies,
  };
})();
