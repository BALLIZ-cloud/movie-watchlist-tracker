const state = {
  results: [],
  watchlist: [],
};

const dom = {
  searchForm: document.getElementById('search-form'),
  searchInput: document.getElementById('search-input'),
  searchButton: document.getElementById('search-button'),
  searchError: document.getElementById('search-error'),
  statusBanner: document.getElementById('status-banner'),
  resultsGrid: document.getElementById('results-grid'),
  watchlistGrid: document.getElementById('watchlist-grid'),
  savedCount: document.getElementById('saved-count'),
  watchedCount: document.getElementById('watched-count'),
};

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function setBanner(message, tone = 'info') {
  if (!message) {
    dom.statusBanner.textContent = '';
    dom.statusBanner.className = 'banner hidden';
    return;
  }

  dom.statusBanner.textContent = message;
  dom.statusBanner.className = `banner banner-${tone}`;
}

function updateStats() {
  const watchedCount = state.watchlist.filter((movie) => movie.watched).length;
  dom.savedCount.textContent = String(state.watchlist.length);
  dom.watchedCount.textContent = String(watchedCount);
}

function watchlistIds() {
  return new Set(state.watchlist.map((movie) => movie.tmdb_id));
}

function movieMeta(movie) {
  const year = movie.release_year || 'TBA';
  const rating = movie.vote_average ? `${movie.vote_average}/10` : 'No rating yet';
  return `${year} / ${rating}`;
}

function posterMarkup(movie) {
  if (movie.poster_url) {
    return `<img src="${escapeHtml(movie.poster_url)}" alt="${escapeHtml(movie.title)} poster" loading="lazy">`;
  }

  return `<div class="poster-fallback">No poster</div>`;
}

function resultCard(movie) {
  const ids = watchlistIds();
  const isSaved = ids.has(movie.tmdb_id);

  return `
    <article class="movie-card">
      <div class="movie-card__poster">${posterMarkup(movie)}</div>
      <div class="movie-card__body">
        <div class="movie-card__header">
          <div>
            <p class="movie-card__meta">${escapeHtml(movieMeta(movie))}</p>
            <h3>${escapeHtml(movie.title)}</h3>
          </div>
          <span class="pill ${isSaved ? 'pill-solid' : ''}">${isSaved ? 'Saved' : 'TMDB'}</span>
        </div>
        <p class="movie-card__overview">${escapeHtml(movie.overview || 'No synopsis available.')}</p>
        <div class="movie-card__actions">
          <button class="ghost-button" data-action="add" data-id="${movie.tmdb_id}" ${isSaved ? 'disabled' : ''}>
            ${isSaved ? 'Already in watchlist' : 'Add to watchlist'}
          </button>
        </div>
      </div>
    </article>
  `;
}

function watchlistCard(movie) {
  return `
    <article class="movie-card ${movie.watched ? 'movie-card-watched' : ''}">
      <div class="movie-card__poster">${posterMarkup(movie)}</div>
      <div class="movie-card__body">
        <div class="movie-card__header">
          <div>
            <p class="movie-card__meta">${escapeHtml(movieMeta(movie))}</p>
            <h3>${escapeHtml(movie.title)}</h3>
          </div>
          <span class="pill ${movie.watched ? 'pill-success' : 'pill-warning'}">${movie.watched ? 'Watched' : 'Queued'}</span>
        </div>
        <p class="movie-card__overview">${escapeHtml(movie.overview || 'No synopsis available.')}</p>
        <div class="movie-card__actions">
          <button class="ghost-button" data-action="toggle" data-id="${movie.tmdb_id}">
            ${movie.watched ? 'Mark as queued' : 'Mark as watched'}
          </button>
          <button class="ghost-button ghost-button-danger" data-action="delete" data-id="${movie.tmdb_id}">
            Remove
          </button>
        </div>
      </div>
    </article>
  `;
}

function renderResults() {
  if (state.results.length === 0) {
    dom.resultsGrid.innerHTML = `
      <div class="empty-state">
        <h3>No search results yet</h3>
        <p>Search for a movie title to pull in TMDB results.</p>
      </div>
    `;
    return;
  }

  dom.resultsGrid.innerHTML = state.results.map(resultCard).join('');
}

function renderWatchlist() {
  if (state.watchlist.length === 0) {
    dom.watchlistGrid.innerHTML = `
      <div class="empty-state">
        <h3>Your watchlist is empty</h3>
        <p>Add a movie from the search results to start tracking it.</p>
      </div>
    `;
    updateStats();
    return;
  }

  dom.watchlistGrid.innerHTML = state.watchlist.map(watchlistCard).join('');
  updateStats();
}

async function parseJson(response) {
  const payload = await response.json();

  if (!response.ok || !payload.ok) {
    throw new Error(payload.message || 'Request failed.');
  }

  return payload;
}

async function fetchWatchlist() {
  try {
    const response = await fetch('/DB_Ops.php');
    const payload = await parseJson(response);
    state.watchlist = payload.movies;
    renderWatchlist();
    renderResults();
  } catch (error) {
    renderWatchlist();
    setBanner(error.message, 'error');
  }
}

async function handleSearch(event) {
  event.preventDefault();
  const query = dom.searchInput.value.trim();

  if (query.length < 2) {
    dom.searchError.textContent = 'Please enter at least 2 characters before searching.';
    return;
  }

  dom.searchError.textContent = '';
  setBanner('Searching TMDB...', 'info');
  dom.searchButton.disabled = true;

  try {
    if (!window.MovieApiOps || typeof window.MovieApiOps.searchMovies !== 'function') {
      throw new Error('API layer is not ready. Refresh the page and try again.');
    }

    const payload = await window.MovieApiOps.searchMovies(query);
    state.results = payload.movies;
    renderResults();
    setBanner(`Loaded ${payload.movies.length} movie result${payload.movies.length === 1 ? '' : 's'}.`, 'success');
  } catch (error) {
    state.results = [];
    renderResults();
    setBanner(error.message, 'error');
  } finally {
    dom.searchButton.disabled = false;
  }
}

function lookupMovie(id) {
  return state.results.find((movie) => movie.tmdb_id === id) || state.watchlist.find((movie) => movie.tmdb_id === id);
}

async function updateWatchlist(action, tmdbId) {
  const movie = lookupMovie(tmdbId);
  setBanner('Syncing watchlist...', 'info');

  try {
    const response = await fetch('/DB_Ops.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        action,
        tmdb_id: tmdbId,
        movie,
      }),
    });

    const payload = await parseJson(response);
    state.watchlist = payload.movies;
    renderWatchlist();
    renderResults();

    if (action === 'add') {
      setBanner(`Saved "${movie.title}" to your watchlist.`, 'success');
    } else if (action === 'toggle') {
      const updated = payload.movie;
      setBanner(
        updated.watched ? `Marked "${updated.title}" as watched.` : `Moved "${updated.title}" back to queued.`,
        'success'
      );
    } else if (action === 'delete') {
      setBanner('Movie removed from your watchlist.', 'success');
    }
  } catch (error) {
    setBanner(error.message, 'error');
  }
}

dom.searchForm.addEventListener('submit', handleSearch);

dom.resultsGrid.addEventListener('click', (event) => {
  const button = event.target.closest('button[data-action="add"]');
  if (!button) {
    return;
  }

  updateWatchlist('add', Number(button.dataset.id));
});

dom.watchlistGrid.addEventListener('click', (event) => {
  const button = event.target.closest('button[data-action]');
  if (!button) {
    return;
  }

  updateWatchlist(button.dataset.action, Number(button.dataset.id));
});

if (window.APP_CONFIG.apiKeyMissing) {
  setBanner('Search is disabled until the TMDB API key is configured in .env.', 'error');
}

renderResults();
renderWatchlist();
fetchWatchlist();
