const API_BASE = ""; // тот же хост, что и страница

async function apiRequest(method, path, data) {
    const options = {
        method,
        headers: {
            Accept: "application/json",
        },
    };

    if (data !== undefined && data !== null) {
        options.headers["Content-Type"] = "application/json";
        options.body = JSON.stringify(data);
    }

    const res = await fetch(API_BASE + path, options);
    const text = await res.text();
    let json = null;
    try {
        json = JSON.parse(text);
    } catch (e) {
        //
    }

    return {
        status: res.status,
        body: text,
        json,
    };
}

//

const apiStatusEl = document.getElementById("api-status");

const form = document.getElementById("queue-form");
const usernameInput = document.getElementById("username");
const passwordInput = document.getElementById("password");
const regionSelect = document.getElementById("region");
const modeSelect = document.getElementById("game-mode");
const joinBtn = document.getElementById("join-btn");

const currentPlayerEl = document.getElementById("current-player");
const currentModeEl = document.getElementById("current-mode");
const ticketIdEl = document.getElementById("ticket-id");
const queueStateEl = document.getElementById("queue-state");
const logEl = document.getElementById("queue-log");

const lobbySubtitleEl = document.getElementById("lobby-subtitle");
const lobbyModeEl = document.getElementById("lobby-mode");
const lobbyRegionEl = document.getElementById("lobby-region");
const lobbyMmrEl = document.getElementById("lobby-mmr");
const lobbyTeamsEl = document.getElementById("lobby-teams");
const resetLobbyBtn = document.getElementById("reset-lobby-btn");

const team1LabelEl = document.getElementById("team1-score");
const team2LabelEl = document.getElementById("team2-score");

// ----- STATE -----

let currentPlayer = null; // {id, username, mmr, region}
let currentGameMode = null;
let currentTicketId = null;
let pollTimer = null;

// ----- HELPERS -----

function appendLog(line) {
    const ts = new Date().toLocaleTimeString("ru-RU", { hour12: false });
    logEl.textContent += `[${ts}] ${line}\n`;
    logEl.scrollTop = logEl.scrollHeight;
}

function setQueueState(state) {
    queueStateEl.className = "queue-status__badge";
    if (state === "in_queue") {
        queueStateEl.classList.add("queue-status__badge--in-queue");
        queueStateEl.textContent = "в очереди";
    } else if (state === "matched") {
        queueStateEl.classList.add("queue-status__badge--matched");
        queueStateEl.textContent = "матч найден";
    } else {
        queueStateEl.textContent = "не в очереди";
    }
}

function resetLobby() {
    lobbySubtitleEl.textContent = "Матч ещё не найден.";
    lobbyModeEl.textContent = "5v5";
    lobbyRegionEl.textContent = "EU";
    lobbyMmrEl.textContent = "MMR: –";
    lobbyTeamsEl.innerHTML = "";
    team1LabelEl.textContent = "TEAM A";
    team2LabelEl.textContent = "TEAM B";
}

// ----- API PING -----

(async function pingApi() {
    try {
        const res = await apiRequest("GET", "/api/ping");
        if (res.status === 200) {
            apiStatusEl.textContent = "API: online";
            apiStatusEl.classList.remove("badge--checking", "badge--error");
            apiStatusEl.classList.add("badge--ok");
        } else {
            apiStatusEl.textContent = "API: ошибка";
            apiStatusEl.classList.remove("badge--checking", "badge--ok");
            apiStatusEl.classList.add("badge--error");
        }
    } catch (e) {
        apiStatusEl.textContent = "API: нет связи";
        apiStatusEl.classList.remove("badge--checking", "badge--ok");
        apiStatusEl.classList.add("badge--error");
    }
})();

// ----- AUTH (register + login) -----

async function ensurePlayer(username, password, region) {
    appendLog(`Пробуем зарегистрировать игрока ${username}...`);

    let regResp;
    try {
        regResp = await apiRequest("POST", "/api/register", {
            username,
            password,
            region,
        });
    } catch (e) {
        appendLog("Не удалось обратиться к /api/register");
        return null;
    }

    if (regResp.status === 200 || regResp.status === 201) {
        appendLog("Игрок успешно зарегистрирован.");
    } else if (
        regResp.status === 400 &&
        regResp.json &&
        regResp.json.error &&
        regResp.json.error.toLowerCase().includes("username already taken")
    ) {
        appendLog("Игрок уже существует, ок.");
    } else {
        appendLog(
            `Неожиданный ответ от /api/register: ${regResp.status} ${regResp.body}`,
        );
    }

    appendLog(`Логинимся как ${username}...`);

    let loginResp;
    try {
        loginResp = await apiRequest("POST", "/api/login", {
            username,
            password,
        });
    } catch (e) {
        appendLog("Не удалось обратиться к /api/login");
        return null;
    }

    if (loginResp.status !== 200 || !loginResp.json?.player_id) {
        appendLog(
            `Ошибка логина: статус ${loginResp.status}, ответ: ${loginResp.body}`,
        );
        return null;
    }

    const player = {
        id: loginResp.json.player_id,
        username: loginResp.json.username || username,
        mmr: loginResp.json.mmr ?? 1000,
        region: loginResp.json.region || region,
    };

    appendLog(
        `Успешный логин. player_id = ${player.id}, mmr = ${player.mmr}, region = ${player.region}`,
    );

    return player;
}

// ----- QUEUE -----

async function joinQueue(player, mode) {
    appendLog(`Отправляем /api/queue/join (player_id=${player.id}, mode=${mode})`);

    let resp;
    try {
        resp = await apiRequest("POST", "/api/queue/join", {
            player_id: player.id,
            game_mode: mode,
        });
    } catch (e) {
        appendLog("Не удалось обратиться к /api/queue/join");
        return null;
    }

    if (resp.status !== 200 || !resp.json?.ticket_id) {
        appendLog(
            `Ошибка очереди: статус ${resp.status}, ответ: ${resp.body}`,
        );
        return null;
    }

    const ticketId = resp.json.ticket_id;
    const status = resp.json.status ?? "in_queue";

    appendLog(`Тикет создан: #${ticketId}, статус = ${status}`);

    currentTicketId = ticketId;
    currentGameMode = mode;
    currentPlayerEl.textContent = player.username;
    currentModeEl.textContent = mode;
    ticketIdEl.textContent = ticketId;
    setQueueState(status);

    return { ticketId, status };
}

async function pollQueueStatus() {
    if (!currentPlayer || !currentGameMode) return;

    const url = `/api/queue/status?player_id=${encodeURIComponent(
        currentPlayer.id,
    )}&game_mode=${encodeURIComponent(currentGameMode)}`;

    let resp;
    try {
        resp = await apiRequest("GET", url);
    } catch (e) {
        appendLog("Не удалось обратиться к /api/queue/status");
        return;
    }

    if (resp.status !== 200 || !resp.json?.status) {
        appendLog(
            `Странный ответ от /api/queue/status: ${resp.status} ${resp.body}`,
        );
        return;
    }

    const status = resp.json.status;
    setQueueState(status);
    appendLog(`Статус очереди: ${status}`);

    if (status === "matched") {
        clearInterval(pollTimer);
        pollTimer = null;
        appendLog("Матч найден, загружаем состав команд.");
        await loadLastMatchForPlayer();
    }
}

// ----- MATCH / LOBBY -----

async function loadLastMatchForPlayer() {
    if (!currentPlayer) return;

    let resp;
    try {
        resp = await apiRequest(
            "GET",
            `/api/matches/last?player_id=${encodeURIComponent(currentPlayer.id)}`,
        );
    } catch (e) {
        appendLog("Не удалось обратиться к /api/matches/last");
        return;
    }

    if (resp.status !== 200 || !resp.json) {
        appendLog(
            `Ошибка при получении матча: ${resp.status} ${resp.body}`,
        );
        return;
    }

    renderLobbyFromMatch(resp.json);
}

function renderLobbyFromMatch(match) {
    lobbySubtitleEl.textContent = "Матч собран: 2 команды по 5 игроков.";
    lobbyModeEl.textContent = match.game_mode || "5v5";
    lobbyRegionEl.textContent = match.region || "EU";
    lobbyMmrEl.textContent =
        match.avg_mmr != null ? `MMR: ${match.avg_mmr}` : "MMR: –";

    const teams = match.teams || [];

    if (teams[0]) {
        team1LabelEl.textContent = teams[0].team_name || "TEAM A";
    } else {
        team1LabelEl.textContent = "TEAM A";
    }

    if (teams[1]) {
        team2LabelEl.textContent = teams[1].team_name || "TEAM B";
    } else {
        team2LabelEl.textContent = "TEAM B";
    }

    lobbyTeamsEl.innerHTML = "";

    teams.forEach((team) => {
        const col = document.createElement("div");
        col.className = "team-column";

        const header = document.createElement("div");
        header.className = "team-column__header";

        const nameSpan = document.createElement("span");
        nameSpan.className = "team-column__name";
        nameSpan.textContent = team.team_name || "TEAM";

        const avg =
            team.players && team.players.length
                ? team.players.reduce((s, p) => s + (p.mmr ?? 0), 0) /
                  team.players.length
                : 0;

        const avgSpan = document.createElement("span");
        avgSpan.className = "team-column__avgmmr";
        avgSpan.textContent = team.players && team.players.length
            ? `AVG ${Math.round(avg)}`
            : "AVG –";

        header.appendChild(nameSpan);
        header.appendChild(avgSpan);
        col.appendChild(header);

        (team.players || []).forEach((p) => {
            const row = document.createElement("div");
            row.className = "player-row";
            if (p.username === currentPlayer.username) {
                row.classList.add("player-row--self");
            }

            const avatar = document.createElement("div");
            avatar.className = "player-row__avatar";
            avatar.textContent = p.username?.[0]?.toUpperCase() ?? "?";

            const name = document.createElement("div");
            name.className = "player-row__name";
            name.textContent = p.username;

            const mmr = document.createElement("div");
            mmr.className = "player-row__mmr";
            mmr.textContent = p.mmr ?? "–";

            row.appendChild(avatar);
            row.appendChild(name);
            row.appendChild(mmr);

            col.appendChild(row);
        });

        lobbyTeamsEl.appendChild(col);
    });
}

// ----- EVENTS -----

form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const username = usernameInput.value.trim();
    const password = passwordInput.value || "123456";
    const region = regionSelect.value;
    const mode = modeSelect.value;

    if (!username) return;

    resetLobby();
    setQueueState("none");
    currentTicketId = null;

    joinBtn.disabled = true;
    joinBtn.textContent = "Ждём ответ...";

    try {
        const player = await ensurePlayer(username, password, region);
        if (!player) {
            joinBtn.disabled = false;
            joinBtn.textContent = "Встать в очередь";
            return;
        }

        currentPlayer = player;

        const joinInfo = await joinQueue(player, mode);
        if (!joinInfo) {
            joinBtn.disabled = false;
            joinBtn.textContent = "Встать в очередь";
            return;
        }

        // стартуем опрос очереди
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollQueueStatus, 2000);
    } finally {
        joinBtn.disabled = false;
        joinBtn.textContent = "Встать в очередь";
    }
});

resetLobbyBtn.addEventListener("click", () => {
    resetLobby();
    appendLog("Лобби сброшено (только на фронте).");
});
