// game/domain/tuning.ts
var TUNING = Object.freeze({
  layout: Object.freeze({
    /** @status APPROVED — the 21 v0.3 mobile artboards */
    baselineViewportWidthPx: 390,
    /** @status APPROVED — the 21 v0.3 mobile artboards */
    baselineViewportHeightPx: 844,
    /** @status APPROVED — v0.3 desktop board */
    desktopPlayColumnPx: 460
  }),
  road: Object.freeze({
    /** @status PROPOSED — fraction of the play column; measure against v0.3 (CR-3) */
    widthRatio: 0.72
  }),
  lane: Object.freeze({
    /** @status APPROVED — LEFT / CENTER / RIGHT */
    count: 3,
    /** @status APPROVED — a run begins in the centre lane */
    startIndex: 1,
    /** @status PROPOSED */
    transitionMs: 160,
    /**
     * Where in the transition the collision lane changes.
     *
     * At the midpoint, so a lane change feels committed: neither "I was already
     * out of the way" at the start nor "I was clipped by the lane I left" at the
     * end.
     *
     * @status PROPOSED
     */
    occupancySwitchAtRatio: 0.5,
    /** @status PROPOSED — core-run.md §3.3, must be confirmed before M6 closes (CR-1) */
    midAirChangeAllowed: true
  }),
  input: Object.freeze({
    /**
     * How long a pending input stays queued waiting to become legal.
     *
     * Without a buffer, a correct input during a lane transition is silently
     * dropped and the game feels unresponsive at exactly the moments that matter.
     *
     * @status PROPOSED
     */
    bufferMs: 120,
    /** @status PROPOSED — a later input replaces an earlier pending one */
    bufferDepth: 1
  }),
  swipe: Object.freeze({
    /** @status PROPOSED — below this, treat as a tap */
    minDistancePx: 24,
    /** @status PROPOSED — slower drags are not swipes */
    maxDurationMs: 400,
    /** @status PROPOSED — the dominant axis must exceed the other by this, so diagonals resolve predictably */
    axisDominanceRatio: 1.5
  }),
  jump: Object.freeze({
    /** @status APPROVED — the target airborne duration */
    airborneMs: 650,
    /** @status PROPOSED — at the 390 px baseline; scales with the play column */
    apexHeightPx: 96
  }),
  sim: Object.freeze({
    /** @status PROPOSED — two steps per frame at 60 fps (RNG-3, GE-1 both OPEN) */
    fixedStepHz: 120,
    /**
     * The most steps one frame may simulate before the excess is discarded.
     *
     * Spiral protection. After a tab stall, a GC pause or a device sleep,
     * simulating the whole backlog instantly would teleport the player through
     * everything that accumulated. The excess is dropped, not played.
     *
     * @status PROPOSED
     */
    maxCatchUpSteps: 8
  }),
  run: Object.freeze({
    /**
     * The readiness beat before a run becomes interactive.
     *
     * The player sees the lane and the character before anything can happen.
     * Input is accepted from the moment the run is interactive; the guarantee is
     * about hazards, not about locking the controls.
     *
     * @status PROPOSED
     */
    readyMs: 1500,
    /**
     * No hazard may be **reachable** before this.
     *
     * Reachable, not spawned: obstacles are created a lookahead ahead of the
     * player so the renderer can show them approaching, and the guarantee the
     * player experiences is about when one can first hurt them.
     *
     * @status PROPOSED
     */
    firstHazardMinMs: 2500
  }),
  /**
   * The scrolling world, in road units.
   *
   * One unit is one lane width, used for both axes so the lateral and
   * longitudinal envelopes are comparable. The player's reference point sits at
   * distance zero; obstacles are created ahead at a positive distance and move
   * toward it. Nothing here is a pixel — the engine converts.
   */
  world: Object.freeze({
    /** @status PROPOSED — road units per second at the base difficulty speed */
    baseScrollUnitsPerS: 3,
    /** @status PROPOSED — how much road the player can see ahead */
    visibleUnits: 10,
    /** @status PROPOSED — obstacles are created this far ahead, beyond the visible road */
    spawnLookaheadUnits: 14,
    /** @status PROPOSED — an obstacle is removed once its trailing edge is this far behind */
    despawnBehindUnits: 2
  }),
  obstacle: Object.freeze({
    /**
     * Longitudinal footprint of a v1 obstacle.
     *
     * Was 1.0, which together with a 0.8 player made the overlap window 1.8
     * units — 600 ms at the base scroll speed, against an APPROVED 650 ms jump
     * arc. That left a **42 ms** window in which a jump could be started and
     * still clear a barrier, at the slowest point of the run: the jump verb was
     * effectively unusable in the first half-minute, and got *easier* as the
     * game sped up, which is backwards. 0.7 with a 0.5 player gives 400 ms of
     * overlap and a ~250 ms window at the start, widening from there.
     *
     * @status PROPOSED
     */
    defaultLengthUnits: 0.7,
    jumpable: Object.freeze({
      /** @status APPROVED — a jumpable obstacle is cleared while airborne */
      clearableByJump: true
    })
  }),
  collision: Object.freeze({
    /** @status PROPOSED — the player's longitudinal footprint, centred on the reference point */
    playerLengthUnits: 0.5
  }),
  hearts: Object.freeze({
    /** @status APPROVED */
    start: 3,
    /** @status APPROVED */
    max: 3,
    /** @status APPROVED */
    costPerCollision: 1
  }),
  invuln: Object.freeze({
    /** @status PROPOSED — post-hit invulnerability, so one hazard cannot drain the run */
    postHitMs: 1200,
    /** @status PROPOSED — presentation only; the domain exposes the state, not the blink */
    blinkHz: 10
  }),
  /*
   * `nearMiss.enabled`, `nearMiss.awardsScore`, `nearMiss.oneEventPerObstacle`,
   * `escape.validateJoins` and `obstacle.laneBlocking.clearableByJump` are in
   * the registry and deliberately **not** here.
   *
   * Each is an APPROVED statement of policy rather than a knob, and each is
   * already structural in the code: a near miss cannot award score because
   * there is no score; it cannot fire twice because an obstacle's outcome is
   * set once; joins are validated because a test validates them; and a lane
   * blocker is not cleared by a jump because `collision.ts` only exempts the
   * jumpable class. Reading a `true` and branching on it would add a way for
   * the invariant to be switched off, which is the opposite of enforcing it.
   */
  nearMiss: Object.freeze({
    /**
     * Lane widths, centre to centre, against the obstacle's lane.
     *
     * Was 0.55, which made the approved earning path unreachable: an adjacent
     * lane is exactly 1.0 centre-to-centre, so a settled pass could never
     * qualify and the mechanic only fired in an 8 ms sliver mid-transition.
     * 1.25 admits the adjacent lane and still excludes two lanes away (2.0),
     * which is exactly what the approved rule describes. The measurement basis
     * is unchanged; only the number moved. CR-6 tuning correction.
     *
     * @status PROPOSED
     */
    lateralEnvelopeUnits: 1.25,
    /** @status PROPOSED — how close along the road counts as passing */
    longitudinalEnvelopeUnits: 0.75,
    /**
     * Headroom, as a fraction of the jump apex, below which clearing a
     * `JUMPABLE` obstacle counts as a near miss.
     *
     * The registry writes this in "units"; read here as apex-normalised height,
     * because the domain has no pixels and the apex is the only vertical scale
     * it owns. Recorded as an interpretation, not a decision.
     *
     * @status PROPOSED
     */
    verticalClearanceUnits: 0.4
  }),
  difficulty: Object.freeze({
    speed: Object.freeze({
      /** @status PROPOSED — reference scroll speed */
      base: 1,
      /** @status PROPOSED — never faster than this */
      ceiling: 1.85,
      /** @status PROPOSED — reaches about 63% of the gap at this many seconds */
      timeConstantS: 90
    }),
    density: Object.freeze({
      /** @status PROPOSED — fraction of road length occupied */
      base: 0.25,
      /** @status PROPOSED */
      ceiling: 0.55
    }),
    decisionsPerMin: Object.freeze({
      /** @status PROPOSED */
      base: 14,
      /** @status PROPOSED */
      ceiling: 38
    }),
    /**
     * Tier 1…Tier 5 start times, in seconds. Tier 5 is terminal.
     *
     * The repository carried 0 / 25 / 60 / 110 / 180 from its first commit and
     * never changed it, which let a PROPOSED value quietly read as source of
     * truth. It had never been approved: DO-3 recorded the thresholds as
     * unconfirmed from M0.5 onward, and the M0.5 note changed only the labels.
     *
     * The product owner settled it during the M6 adversarial remediation, and
     * these are that decision. Approved as *thresholds* only — the soft-cap
     * ceilings DO-3 also asked about are still open and still PROPOSED, so the
     * decision narrows DO-3 rather than closing it.
     *
     * @status APPROVED
     */
    tierStartsS: Object.freeze([0, 30, 60, 120, 180])
  }),
  generator: Object.freeze({
    /** @status PROPOSED — patterns used within this many selections are excluded */
    repeatCooldown: 3,
    /** @status PROPOSED — the gap floor per tier, regardless of density */
    minGapUnits: Object.freeze([6, 5.5, 5, 4.5, 4])
  }),
  /**
   * Score.
   *
   * Three components, never a fourth mutable total: the displayed score is the
   * sum, so it cannot drift away from the parts that produced it.
   *
   * `perPaw` is APPROVED; the other two are not. The registry records what the
   * approved value did to the run totals — the v0.3 boards' 1,200-5,900 range
   * now spans roughly one to four minutes rather than 1.5 to five — and
   * `distancePerSecond` is the PROPOSED lever that owns the difference.
   */
  score: Object.freeze({
    /** @status PROPOSED — per second at the base speed; scales with the real scroll rate */
    distancePerSecond: 10,
    /** @status APPROVED — awarded once per collected Paw Token, magnet or not */
    perPaw: 10,
    /** @status APPROVED — the only multiplier in v1, and it never compounds */
    slayyyMultiplier: 2
  }),
  /**
   * Paw Tokens: the one collectible in v1.
   *
   * Placement is drawn from the **collectible** RNG stream, never the pattern
   * stream, so adding or retuning tokens cannot move a single obstacle.
   */
  paw: Object.freeze({
    /** @status APPROVED — Paw Tokens per Loli Bonus, with the overflow preserved */
    loliThreshold: 200,
    /** @status PROPOSED — how close to the threshold the HUD starts showing n/200 */
    hudThresholdProximity: 25,
    /** @status PROPOSED — longitudinal footprint, matched to the player's own */
    lengthUnits: 0.5,
    /** @status PROPOSED — lateral reach for collection, centre to centre */
    collectLateralUnits: 0.5,
    /** @status PROPOSED — most tokens one pattern may carry */
    perPatternMax: 3,
    /** @status PROPOSED — spacing between tokens in a run of them */
    spacingUnits: 1.2,
    /** @status PROPOSED — road distance between one token group and the next */
    groupGapUnits: 8
  }),
  /**
   * The Loli Bonus: a companion, not a coin.
   *
   * It magnetises Paw Tokens and does nothing else — no invulnerability, no
   * extra life, no healing, no multiplier of its own. Overlapping SLAYYY is
   * allowed and still yields ×2, never ×4.
   */
  loli: Object.freeze({
    /** @status APPROVED — the active companion window, ≈ 8 s */
    durationMs: 8e3,
    /** @status PROPOSED — the "puf" entrance, before the magnet engages */
    enteringMs: 600,
    /** @status PROPOSED — the exit flourish, after the magnet disengages */
    exitingMs: 500,
    /** @status PROPOSED — lateral attraction reach, in lane widths */
    magnetRadiusUnits: 1.5,
    /**
     * How far up the road the magnet reaches.
     *
     * Chosen to match `world.visibleUnits`, because "attracts nearby paws"
     * cannot honestly mean a token the player has never seen. Held as its own
     * value rather than read from `world`: this is a gameplay reach, and the
     * day the camera changes is not the day the magnet should.
     *
     * @status PROPOSED
     */
    magnetReachUnits: 10,
    /** @status PROPOSED — how fast an attracted token closes, in lane-units per second */
    magnetPullPerSecond: 6,
    /** @status APPROVED — two companions never run at once; further earns queue */
    concurrentInstances: 1
  }),
  /**
   * SLAYYY: earned across a run, spent by the player, never automatic.
   *
   * The meter is run-local and starts empty every run. Filling it does not fire
   * it — `autoActivate` is APPROVED false — so a full meter becomes READY and
   * waits for a deliberate press.
   */
  slayyy: Object.freeze({
    /** @status APPROVED — the active window, ≈ 5 s */
    durationMs: 5e3,
    /** @status PROPOSED — a full meter */
    chargeMax: 100,
    /** @status PROPOSED — the slow fill, from surviving distance */
    chargePerSecond: 1.6,
    /** @status PROPOSED — the fast fill, per Paw Token collected */
    chargePerPaw: 1.3,
    /** @status PROPOSED — no decay in v1; a meter never drains on its own */
    decayPerSecond: 0
  }),
  /**
   * The first-run tutorial.
   *
   * Every value here is about **teaching pace**, never about difficulty: the
   * tutorial cannot hurt the player, so nothing in this group can make it
   * harder or easier, only slower or quicker to read. That is why none of it
   * appears in the difficulty curve and why `repeatGapUnits` is a gap rather
   * than a timeout — the player is never racing anything.
   */
  tutorial: Object.freeze({
    /** @status PROPOSED — how long the opening greeting holds before the first lesson */
    introDwellMs: 2600,
    /** @status PROPOSED — tutorial.md §3.2; re-prompt with more explicit guidance */
    repromptMs: 3e3,
    /** @status PROPOSED — how far ahead the first prop of a lesson is placed */
    firstCueUnits: 9,
    /** @status PROPOSED — the gap before an unsatisfied lesson presents its prop again */
    repeatGapUnits: 11,
    /** @status PROPOSED — how long a passed lesson is celebrated before the next opens */
    celebrateMs: 900,
    /** @status PROPOSED — the closing practice stretch, applying what was taught */
    finalPracticeUnits: 34
  }),
  escape: Object.freeze({
    /** @status PROPOSED — the solver may spend at most this many actions per pattern */
    maxActionsPerPattern: 2,
    /** @status PROPOSED — subtracted from the window; turns "possible" into "fair" */
    reactionBudgetMs: 350,
    /** @status PROPOSED — how finely the solver considers acting; well under the reaction budget */
    solverGridMs: 100,
    /** @status PROPOSED — search ceiling, so a misconfigured pattern fails rather than hangs */
    solverMaxSteps: 2e3
  })
});
var STEP_MS = 1e3 / TUNING.sim.fixedStepHz;
var JUMP_ARC = Object.freeze((() => {
  const apexMs = TUNING.jump.airborneMs / 2;
  const apexSeconds = apexMs / 1e3;
  const gravityPxPerS2 = 2 * TUNING.jump.apexHeightPx / (apexSeconds * apexSeconds);
  return {
    apexMs,
    gravityPxPerS2,
    initialVelocityPxPerS: gravityPxPerS2 * apexSeconds
  };
})());

// game/domain/rng.ts
var MAX_SEED = 4294967295;
function splitmix32(seed) {
  let state = seed >>> 0;
  return () => {
    state = state + 2654435769 >>> 0;
    let z = state;
    z = Math.imul(z ^ z >>> 16, 569420461) >>> 0;
    z = Math.imul(z ^ z >>> 15, 1935289751) >>> 0;
    return (z ^ z >>> 15) >>> 0;
  };
}
function createStream(seed) {
  const next = splitmix32(seed);
  const a = next();
  const b = next();
  const c = next();
  const d = next();
  if ((a | b | c | d) === 0) {
    return { a: 2654435769, b: 608135816, c: 3084996962, d: 2246822507 };
  }
  return { a, b, c, d };
}
function createRngState(seed) {
  return {
    pattern: createStream((seed ^ 521288629) >>> 0),
    collectible: createStream((seed ^ 668265263) >>> 0),
    cosmetic: createStream((seed ^ 374761393) >>> 0)
  };
}
function nextUint32(stream) {
  let t = stream.d;
  const s = stream.a;
  t ^= t << 11 >>> 0;
  t >>>= 0;
  t ^= t >>> 8;
  t ^= s ^ s >>> 19;
  t >>>= 0;
  return {
    value: t,
    state: { a: t, b: stream.a, c: stream.b, d: stream.c }
  };
}
function nextFloat(stream) {
  const { value, state } = nextUint32(stream);
  return { value: value / 4294967296, state };
}
function nextIntInclusive(stream, min, max) {
  if (!Number.isSafeInteger(min) || !Number.isSafeInteger(max)) {
    throw new RangeError(
      `nextIntInclusive: bounds must be safe integers (got min ${String(min)}, max ${String(max)})`
    );
  }
  if (max < min) {
    throw new RangeError(`nextIntInclusive: max (${max}) is below min (${min})`);
  }
  const { value, state } = nextFloat(stream);
  return { value: min + Math.floor(value * (max - min + 1)), state };
}

// game/domain/jump.ts
function isAirborne(state) {
  return state.jumpElapsedMs !== null;
}
function canJump(state) {
  return state.jumpElapsedMs === null;
}
function startJump(state) {
  if (!canJump(state)) return state;
  return { ...state, jumpElapsedMs: 0 };
}
function jumpHeightPx(state) {
  const elapsedMs = state.jumpElapsedMs;
  if (elapsedMs === null) return 0;
  const t = elapsedMs / 1e3;
  const height = JUMP_ARC.initialVelocityPxPerS * t - 0.5 * JUMP_ARC.gravityPxPerS2 * t * t;
  return Math.max(0, height);
}
function advanceJump(state, deltaMs) {
  const elapsedMs = state.jumpElapsedMs;
  if (elapsedMs === null) return state;
  const next = elapsedMs + deltaMs;
  if (next >= TUNING.jump.airborneMs) {
    return { ...state, jumpElapsedMs: null };
  }
  return { ...state, jumpElapsedMs: next };
}

// game/domain/lanes.ts
var MIN_LANE = 0;
var MAX_LANE = TUNING.lane.count - 1;
function isLaneIndex(value) {
  return Number.isInteger(value) && value >= MIN_LANE && value <= MAX_LANE;
}
function laneStep(from, direction) {
  const target = from + direction;
  return isLaneIndex(target) ? target : null;
}
function occupiedLane(state) {
  const transition = state.laneTransition;
  if (transition === null) return state.lane;
  const switchAtMs = TUNING.lane.transitionMs * TUNING.lane.occupancySwitchAtRatio;
  return transition.elapsedMs < switchAtMs ? transition.from : transition.to;
}
function canStartLaneChange(state) {
  if (state.laneTransition !== null) return false;
  if (state.jumpElapsedMs !== null && !TUNING.lane.midAirChangeAllowed) return false;
  return true;
}
function startLaneChange(state, direction) {
  if (!canStartLaneChange(state)) return state;
  const target = laneStep(state.lane, direction);
  if (target === null) return state;
  const transition = { from: state.lane, to: target, elapsedMs: 0 };
  return { ...state, laneTransition: transition };
}
function advanceLaneTransition(state, deltaMs) {
  const transition = state.laneTransition;
  if (transition === null) return state;
  const elapsedMs = transition.elapsedMs + deltaMs;
  if (elapsedMs >= TUNING.lane.transitionMs) {
    return { ...state, lane: transition.to, laneTransition: null };
  }
  return { ...state, laneTransition: { ...transition, elapsedMs } };
}

// game/domain/slayyy.ts
var MS_PER_SECOND = 1e3;
var MILLI = 1e3;
var MICRO = MILLI * MILLI;
var EMPTY_SLAYYY = Object.freeze({
  phase: "charging",
  chargeMicro: 0,
  activeRemainingMs: 0
});
function chargeCeilingMicro() {
  return TUNING.slayyy.chargeMax * MICRO;
}
function addCharge(slayyy, micro) {
  if (slayyy.phase === "active") return slayyy;
  const ceiling = chargeCeilingMicro();
  const chargeMicro = Math.min(ceiling, slayyy.chargeMicro + micro);
  const phase = chargeMicro >= ceiling ? "ready" : slayyy.phase;
  return { ...slayyy, chargeMicro, phase: phase === "cooldown" ? "charging" : phase };
}
function chargeFromTime(state, deltaMs) {
  if (deltaMs === 0) return state.slayyy;
  const micro = Math.round(TUNING.slayyy.chargePerSecond * MICRO * (deltaMs / MS_PER_SECOND));
  return addCharge(state.slayyy, micro);
}
function chargeFromPaws(slayyy, collected) {
  if (collected === 0) return slayyy;
  return addCharge(slayyy, Math.round(TUNING.slayyy.chargePerPaw * MICRO) * collected);
}
function canActivateSlayyy(state) {
  return state.phase === "running" && state.slayyy.phase === "ready";
}
function activateSlayyy(state) {
  if (!canActivateSlayyy(state)) return state;
  return {
    ...state,
    slayyy: {
      phase: "active",
      chargeMicro: 0,
      activeRemainingMs: TUNING.slayyy.durationMs
    },
    slayyyActivations: state.slayyyActivations + 1
  };
}
function advanceSlayyy(state, deltaMs) {
  const slayyy = state.slayyy;
  if (slayyy.phase !== "active") return slayyy;
  const activeRemainingMs = slayyy.activeRemainingMs - deltaMs;
  if (activeRemainingMs > 0) return { ...slayyy, activeRemainingMs };
  return { phase: "charging", chargeMicro: 0, activeRemainingMs: 0 };
}
function primeSlayyy(slayyy) {
  if (slayyy.phase === "active") return slayyy;
  return { phase: "ready", chargeMicro: chargeCeilingMicro(), activeRemainingMs: 0 };
}
function slayyyProtects(state) {
  return state.slayyy.phase === "active";
}

// game/domain/collision.ts
function playerExtent() {
  const half = TUNING.collision.playerLengthUnits / 2;
  return { from: -half, to: half };
}
function overlapsLongitudinally(obstacle) {
  const player = playerExtent();
  return obstacle.distanceUnits < player.to && obstacle.distanceUnits + obstacle.lengthUnits > player.from;
}
function isDamaging(state, obstacle) {
  if (!overlapsLongitudinally(obstacle)) return false;
  if (occupiedLane(state) !== obstacle.lane) return false;
  if (obstacle.kind === "jumpable" && isAirborne(state)) {
    return !TUNING.obstacle.jumpable.clearableByJump;
  }
  return true;
}
function hasPassed(obstacle) {
  return obstacle.distanceUnits + obstacle.lengthUnits + TUNING.nearMiss.longitudinalEnvelopeUnits <= 0;
}
function apexFraction(state) {
  return jumpHeightPx(state) / TUNING.jump.apexHeightPx;
}
function isNearMiss(state, obstacle) {
  const lateral = Math.abs(lanePosition(state) - obstacle.lane);
  if (obstacle.kind === "jumpable") {
    if (occupiedLane(state) !== obstacle.lane) {
      return lateral <= TUNING.nearMiss.lateralEnvelopeUnits && lateral > 0;
    }
    return isAirborne(state) && apexFraction(state) <= TUNING.nearMiss.verticalClearanceUnits;
  }
  return lateral <= TUNING.nearMiss.lateralEnvelopeUnits && lateral > 0;
}
function lanePosition(state) {
  const transition = state.laneTransition;
  if (transition === null) return state.lane;
  const progress = Math.min(1, transition.elapsedMs / TUNING.lane.transitionMs);
  return transition.from + (transition.to - transition.from) * progress;
}
function protectionSources(state) {
  return {
    hitRecovery: state.invulnRemainingMs > 0,
    slayyy: slayyyProtects(state)
  };
}
function isProtected(state) {
  const sources = protectionSources(state);
  return sources.hitRecovery || sources.slayyy;
}
function resolveCollisions(state) {
  if (state.obstacles.length === 0) return { state, heartsLost: 0, nearMisses: 0, coneSafePasses: 0 };
  const invulnerable = isProtected(state);
  let heartsLost = 0;
  let nearMisses = 0;
  let coneSafePasses = 0;
  let changed = false;
  const obstacles = [];
  for (const obstacle of state.obstacles) {
    if (obstacle.outcome !== "pending") {
      obstacles.push(obstacle);
      continue;
    }
    if (isDamaging(state, obstacle)) {
      if (invulnerable || heartsLost > 0) {
        obstacles.push(Object.freeze({ ...obstacle, outcome: "cleared" }));
      } else {
        heartsLost = TUNING.hearts.costPerCollision;
        obstacles.push(Object.freeze({ ...obstacle, outcome: "hit" }));
      }
      changed = true;
      continue;
    }
    if (hasPassed(obstacle)) {
      const near = isNearMiss(state, obstacle);
      if (near) nearMisses++;
      if (obstacle.kind === "lane_blocking") coneSafePasses++;
      obstacles.push(Object.freeze({ ...obstacle, outcome: near ? "near_miss" : "cleared" }));
      changed = true;
      continue;
    }
    obstacles.push(obstacle);
  }
  if (!changed) return { state, heartsLost: 0, nearMisses: 0, coneSafePasses: 0 };
  return { state: { ...state, obstacles }, heartsLost, nearMisses, coneSafePasses };
}

// game/domain/loli.ts
var MS_PER_SECOND2 = 1e3;
var EMPTY_LOLI = Object.freeze({
  phase: "inactive",
  phaseRemainingMs: 0,
  queuedLoliBonuses: 0
});
function applyPawsToCycle(loliCyclePaws, collected) {
  const total = loliCyclePaws + collected;
  const threshold = TUNING.paw.loliThreshold;
  return {
    loliCyclePaws: total % threshold,
    earned: Math.floor(total / threshold)
  };
}
function canStart(state) {
  return state.phase === "running" && state.loli.phase === "inactive";
}
function startIfPossible(state) {
  if (state.loli.queuedLoliBonuses <= 0 || !canStart(state)) return state;
  if (TUNING.loli.concurrentInstances < 1) return state;
  return {
    ...state,
    loli: {
      phase: "entering",
      phaseRemainingMs: TUNING.loli.enteringMs,
      queuedLoliBonuses: state.loli.queuedLoliBonuses - 1
    },
    loliActivations: state.loliActivations + 1
  };
}
function earnLoliBonuses(state, earned) {
  if (earned <= 0) return startIfPossible(state);
  const queuedState = {
    ...state,
    loli: { ...state.loli, queuedLoliBonuses: state.loli.queuedLoliBonuses + earned }
  };
  return startIfPossible(queuedState);
}
function advanceLoli(state, deltaMs) {
  const loli = state.loli;
  if (loli.phase === "inactive") return startIfPossible(state);
  if (deltaMs === 0) return state;
  const remaining = loli.phaseRemainingMs - deltaMs;
  if (remaining > 0) {
    return { ...state, loli: { ...loli, phaseRemainingMs: remaining } };
  }
  const overshoot = -remaining;
  if (loli.phase === "entering") {
    return {
      ...state,
      loli: { ...loli, phase: "active", phaseRemainingMs: Math.max(0, TUNING.loli.durationMs - overshoot) }
    };
  }
  if (loli.phase === "active") {
    return {
      ...state,
      loli: { ...loli, phase: "exiting", phaseRemainingMs: Math.max(0, TUNING.loli.exitingMs - overshoot) }
    };
  }
  return startIfPossible({ ...state, loli: { ...loli, phase: "inactive", phaseRemainingMs: 0 } });
}
function magnetIsActive(state) {
  return state.loli.phase === "active";
}
function applyMagnet(state, deltaMs) {
  if (!magnetIsActive(state) || deltaMs === 0 || state.pawTokens.length === 0) return state;
  const position = lanePosition(state);
  const step2 = TUNING.loli.magnetPullPerSecond * (deltaMs / MS_PER_SECOND2);
  let changed = false;
  const pawTokens = state.pawTokens.map((token) => {
    if (token.distanceUnits > TUNING.loli.magnetReachUnits) return token;
    const gap = position - token.laneOffset;
    const distance = Math.abs(gap);
    if (distance > TUNING.loli.magnetRadiusUnits || distance === 0) return token;
    const move = Math.min(distance, step2);
    const laneOffset = token.laneOffset + Math.sign(gap) * move;
    changed = true;
    return Object.freeze({ ...token, laneOffset });
  });
  if (!changed) return state;
  return { ...state, pawTokens };
}
function clearLoli(state) {
  if (state.loli.phase === "inactive" && state.loli.queuedLoliBonuses === 0) return state;
  return { ...state, loli: EMPTY_LOLI };
}

// game/domain/score.ts
var MILLI2 = 1e3;
var EMPTY_SCORE = Object.freeze({
  distanceMilli: 0,
  collectionMilli: 0,
  bonusMilli: 0
});
function scoreMultiplier(state) {
  return state.slayyy.phase === "active" ? TUNING.score.slayyyMultiplier : 1;
}
function earnPoints(state, component, points) {
  return earnMilli(state, component, points * MILLI2);
}
function earnMilli(state, component, milli) {
  const awarded = Math.round(milli) * scoreMultiplier(state);
  return { ...state.score, [component]: state.score[component] + awarded };
}
function distanceMilliFor(movedUnits) {
  const pointsPerUnit = TUNING.score.distancePerSecond / TUNING.world.baseScrollUnitsPerS;
  return movedUnits * pointsPerUnit * MILLI2;
}
function scoreComponents(state) {
  return {
    distance: Math.floor(state.score.distanceMilli / MILLI2),
    collection: Math.floor(state.score.collectionMilli / MILLI2),
    bonus: Math.floor(state.score.bonusMilli / MILLI2)
  };
}
function scoreTotal(state) {
  const parts = scoreComponents(state);
  return parts.distance + parts.collection + parts.bonus;
}

// game/domain/difficulty.ts
function softCap(base, ceiling, timeConstantS, elapsedMs) {
  const seconds = elapsedMs / MS_PER_SECOND3;
  return ceiling - (ceiling - base) * Math.exp(-seconds / timeConstantS);
}
var MS_PER_SECOND3 = 1e3;
function speedMultiplier(elapsedMs) {
  const { base, ceiling, timeConstantS } = TUNING.difficulty.speed;
  return softCap(base, ceiling, timeConstantS, elapsedMs);
}
function scrollUnitsPerS(elapsedMs) {
  return TUNING.world.baseScrollUnitsPerS * speedMultiplier(elapsedMs);
}
function tierAt(elapsedMs) {
  const starts = TUNING.difficulty.tierStartsS;
  let tier = 1;
  for (let index = 1; index < starts.length; index++) {
    if (elapsedMs >= starts[index] * MS_PER_SECOND3) tier = index + 1;
  }
  return tier;
}
function minGapUnits(tier) {
  return TUNING.generator.minGapUnits[tier - 1] ?? TUNING.generator.minGapUnits[0];
}

// game/domain/patterns.ts
var LEFT = 0;
var CENTRE = 1;
var RIGHT = 2;
var PATTERNS = Object.freeze([
  // --- Tier 1: one obstacle, one decision, generous room ---------------------
  Object.freeze({
    id: "single-cone-left",
    minTier: 1,
    weight: 10,
    lengthUnits: 1,
    entries: Object.freeze([Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: LEFT })]),
    tags: Object.freeze(["single"])
  }),
  Object.freeze({
    id: "single-cone-centre",
    minTier: 1,
    weight: 10,
    lengthUnits: 1,
    entries: Object.freeze([Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: CENTRE })]),
    tags: Object.freeze(["single"])
  }),
  Object.freeze({
    id: "single-cone-right",
    minTier: 1,
    weight: 10,
    lengthUnits: 1,
    entries: Object.freeze([Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: RIGHT })]),
    tags: Object.freeze(["single"])
  }),
  Object.freeze({
    id: "single-barrier-centre",
    minTier: 1,
    weight: 8,
    lengthUnits: 1,
    entries: Object.freeze([Object.freeze({ offsetUnits: 0, kind: "jumpable", lane: CENTRE })]),
    tags: Object.freeze(["single", "forces-jump-or-dodge"])
  }),
  // --- Tier 2: two lanes blocked, exactly one lane open ----------------------
  Object.freeze({
    id: "pair-left-centre",
    minTier: 2,
    weight: 8,
    lengthUnits: 1,
    entries: Object.freeze([
      Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: LEFT }),
      Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: CENTRE })
    ]),
    tags: Object.freeze(["two-lane", "one-escape"])
  }),
  Object.freeze({
    id: "pair-centre-right",
    minTier: 2,
    weight: 8,
    lengthUnits: 1,
    entries: Object.freeze([
      Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: CENTRE }),
      Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: RIGHT })
    ]),
    tags: Object.freeze(["two-lane", "one-escape"])
  }),
  // --- Tier 3: a sequence that needs a planned lane path ---------------------
  Object.freeze({
    id: "stagger-left-then-right",
    minTier: 3,
    weight: 7,
    lengthUnits: 5,
    entries: Object.freeze([
      Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: LEFT }),
      Object.freeze({ offsetUnits: 4, kind: "lane_blocking", lane: RIGHT })
    ]),
    tags: Object.freeze(["sequence", "double-decision"])
  }),
  Object.freeze({
    id: "barrier-then-cone",
    minTier: 3,
    weight: 7,
    lengthUnits: 6,
    entries: Object.freeze([
      Object.freeze({ offsetUnits: 0, kind: "jumpable", lane: CENTRE }),
      Object.freeze({ offsetUnits: 5, kind: "lane_blocking", lane: CENTRE })
    ]),
    tags: Object.freeze(["sequence", "both-verbs"])
  }),
  // --- Tier 4: both verbs in one pattern, tighter --------------------------
  Object.freeze({
    id: "barrier-wall-with-open-lane",
    minTier: 4,
    weight: 6,
    lengthUnits: 1,
    entries: Object.freeze([
      Object.freeze({ offsetUnits: 0, kind: "jumpable", lane: LEFT }),
      Object.freeze({ offsetUnits: 0, kind: "jumpable", lane: CENTRE }),
      Object.freeze({ offsetUnits: 0, kind: "jumpable", lane: RIGHT })
    ]),
    tags: Object.freeze(["forces-jump", "all-lanes"])
  }),
  Object.freeze({
    /*
     * Both verbs, genuinely.
     *
     * A blocker pushes the player out of the centre, and a barrier then covers
     * both lanes they can be in — so the pattern costs one lane change and one
     * jump, which is exactly the budget.
     *
     * The first draft was a two-lane pair followed by a barrier, and the solver
     * refused it from the far lane: escaping a pair already costs both actions,
     * leaving nothing to jump with. That is a real constraint of a two-action
     * budget, not a bug — a pair and a mandatory jump cannot share a pattern.
     */
    id: "cone-then-barrier-wall",
    minTier: 4,
    weight: 6,
    lengthUnits: 6,
    entries: Object.freeze([
      Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: CENTRE }),
      Object.freeze({ offsetUnits: 5, kind: "jumpable", lane: LEFT }),
      Object.freeze({ offsetUnits: 5, kind: "jumpable", lane: RIGHT })
    ]),
    tags: Object.freeze(["sequence", "both-verbs", "double-decision"])
  }),
  // --- Tier 5: the full pool, at the ceilings -------------------------------
  Object.freeze({
    id: "stagger-three-step",
    minTier: 5,
    weight: 5,
    lengthUnits: 9,
    entries: Object.freeze([
      Object.freeze({ offsetUnits: 0, kind: "lane_blocking", lane: CENTRE }),
      Object.freeze({ offsetUnits: 4, kind: "lane_blocking", lane: LEFT }),
      Object.freeze({ offsetUnits: 8, kind: "jumpable", lane: RIGHT })
    ]),
    tags: Object.freeze(["sequence", "both-verbs", "peak"])
  })
]);
function poolForTier(tier) {
  return PATTERNS.filter((pattern) => pattern.minTier <= tier);
}
var TIER_COUNT = TUNING.difficulty.tierStartsS.length;
var LANE_COUNT = TUNING.lane.count;
var LANE_MAX = TUNING.lane.count - 1;

// game/domain/obstacles.ts
var MS_PER_SECOND4 = 1e3;
function scrollDeltaUnits(elapsedMs, deltaMs) {
  return scrollUnitsPerS(elapsedMs) * (deltaMs / MS_PER_SECOND4);
}
function advanceObstacles(state, deltaMs) {
  if (deltaMs === 0) return state;
  const moved = scrollDeltaUnits(state.elapsedMs, deltaMs);
  if (moved === 0 && state.obstacles.length === 0) return state;
  const obstacles = [];
  for (const obstacle of state.obstacles) {
    const distanceUnits = obstacle.distanceUnits - moved;
    if (distanceUnits + obstacle.lengthUnits < -TUNING.world.despawnBehindUnits) continue;
    obstacles.push(Object.freeze({ ...obstacle, distanceUnits }));
  }
  return {
    ...state,
    obstacles,
    distanceUnits: state.distanceUnits + moved
  };
}
function firstHazardDistanceUnits() {
  const settleSeconds = TUNING.run.firstHazardMinMs / MS_PER_SECOND4;
  const reachDistance = TUNING.world.baseScrollUnitsPerS * settleSeconds;
  return Math.max(0, reachDistance - TUNING.world.spawnLookaheadUnits);
}
function selectPattern(pool, stream) {
  const total = pool.reduce((sum, pattern) => sum + pattern.weight, 0);
  if (pool.length === 0 || total <= 0) return { pattern: null, stream };
  const draw = nextIntInclusive(stream, 1, total);
  let cursor = draw.value;
  for (const pattern of pool) {
    cursor -= pattern.weight;
    if (cursor <= 0) return { pattern, stream: draw.state };
  }
  return { pattern: pool[pool.length - 1], stream: draw.state };
}
function eligiblePatterns(state) {
  const pool = poolForTier(tierAt(state.elapsedMs));
  const maxCooldown = Math.max(0, pool.length - MIN_CANDIDATES);
  const cooling = new Set(state.spawn.recentPatternIds.slice(0, maxCooldown));
  const fresh = pool.filter((pattern) => !cooling.has(pattern.id));
  return fresh.length > 0 ? fresh : pool;
}
function advanceSpawning(state) {
  if (state.phase !== "running") return state;
  if (state.tutorial !== null) return state;
  let next = state;
  let guard = 0;
  while (next.distanceUnits >= next.spawn.nextAtUnits && guard < MAX_PATTERNS_PER_STEP) {
    next = emitPattern(next);
    guard++;
  }
  return next;
}
var MAX_PATTERNS_PER_STEP = TUNING.sim.maxCatchUpSteps;
var MIN_CANDIDATES = 2;
function emitPattern(state) {
  const tier = tierAt(state.elapsedMs);
  const chosen = selectPattern(eligiblePatterns(state), state.rng.pattern);
  if (chosen.pattern === null) {
    return {
      ...state,
      spawn: { ...state.spawn, nextAtUnits: state.spawn.nextAtUnits + minGapUnits(tier) }
    };
  }
  const pattern = chosen.pattern;
  const overshoot = state.distanceUnits - state.spawn.nextAtUnits;
  const origin = TUNING.world.spawnLookaheadUnits - overshoot;
  const spawned = [];
  let nextObstacleId = state.nextObstacleId;
  for (const entry of pattern.entries) {
    spawned.push(Object.freeze({
      id: nextObstacleId,
      kind: entry.kind,
      lane: entry.lane,
      distanceUnits: origin + entry.offsetUnits,
      lengthUnits: TUNING.obstacle.defaultLengthUnits,
      outcome: "pending"
    }));
    nextObstacleId++;
  }
  const recent = [pattern.id, ...state.spawn.recentPatternIds].slice(0, TUNING.generator.repeatCooldown);
  return {
    ...state,
    obstacles: [...state.obstacles, ...spawned],
    nextObstacleId,
    rng: { ...state.rng, pattern: chosen.stream },
    spawn: {
      nextAtUnits: state.spawn.nextAtUnits + pattern.lengthUnits + minGapUnits(tier),
      recentPatternIds: recent
    }
  };
}

// game/domain/tutorial-script.ts
var EMPTY = Object.freeze({
  beats: Object.freeze([]),
  repeats: false
});
var TUTORIAL_LESSON_ORDER = Object.freeze([
  "intro",
  "move_left",
  "move_right",
  "dodge_cone",
  "jump_barrier",
  "collect_paw",
  "activate_slayyy",
  "final_practice",
  "complete"
]);
var TUTORIAL_SCRIPT = Object.freeze({
  // The greeting, the two movement lessons and the SLAYYY lesson put nothing on
  // the road: what they teach is an input, and an obstacle would only be
  // something else to think about while learning it.
  intro: EMPTY,
  move_left: EMPTY,
  move_right: EMPTY,
  /*
   * The lesson this milestone exists for.
   *
   * One cone, in the player's own lane, repeating until they go *around* it.
   * Jumping is not special-cased anywhere — `isDamaging` already refuses to
   * clear a `lane_blocking` obstacle for an airborne player, so a jump produces
   * a contact and the contact becomes the correction. The rule teaches itself.
   */
  dodge_cone: Object.freeze({
    beats: Object.freeze([Object.freeze({
      obstacles: Object.freeze([
        Object.freeze({ kind: "lane_blocking", lane: "player", offsetUnits: 0 })
      ]),
      pawTokens: Object.freeze([])
    })]),
    repeats: true
  }),
  // And now the other half of the pair, so the two verbs are learned against
  // each other rather than one of them being assumed to be optional.
  jump_barrier: Object.freeze({
    beats: Object.freeze([Object.freeze({
      obstacles: Object.freeze([
        Object.freeze({ kind: "jumpable", lane: "player", offsetUnits: 0 })
      ]),
      pawTokens: Object.freeze([])
    })]),
    repeats: true
  }),
  // One lane over, so taking it is the movement already taught rather than a
  // token that arrives by standing still.
  collect_paw: Object.freeze({
    beats: Object.freeze([Object.freeze({
      obstacles: Object.freeze([]),
      pawTokens: Object.freeze([
        Object.freeze({ lane: "adjacent", offsetUnits: 0 })
      ])
    })]),
    repeats: true
  }),
  activate_slayyy: EMPTY,
  /*
   * A short run of what was just taught, in order, with no prompt naming the
   * verb. Three beats rather than one placement: each is laid down against the
   * lane the player is in when it arrives, so dodging the cone does not
   * accidentally clear the barrier too.
   */
  final_practice: Object.freeze({
    beats: Object.freeze([
      Object.freeze({
        obstacles: Object.freeze([
          Object.freeze({ kind: "lane_blocking", lane: "player", offsetUnits: 0 })
        ]),
        pawTokens: Object.freeze([])
      }),
      Object.freeze({
        obstacles: Object.freeze([
          Object.freeze({ kind: "jumpable", lane: "player", offsetUnits: 0 })
        ]),
        pawTokens: Object.freeze([])
      }),
      Object.freeze({
        obstacles: Object.freeze([]),
        pawTokens: Object.freeze([
          Object.freeze({ lane: "adjacent", offsetUnits: 0 })
        ])
      })
    ]),
    repeats: false
  }),
  complete: EMPTY
});

// game/domain/types.ts
var LANE_LEFT = 0;
var LANE_RIGHT = 2;

// game/domain/tutorial.ts
var TUTORIAL_LESSON_COUNT = TUTORIAL_LESSON_ORDER.length;
function tutorialLessonIndex(lesson) {
  return TUTORIAL_LESSON_ORDER.indexOf(lesson);
}
function createTutorialState() {
  return {
    lesson: "intro",
    lessonElapsedMs: 0,
    correction: null,
    correctionCount: 0,
    lessonCorrections: 0,
    beatIndex: 0,
    nextCueAtUnits: null,
    cueObstacleIds: [],
    cuePawTokenIds: [],
    cueOverlapInLane: false,
    cueJumped: false,
    celebrateRemainingMs: 0,
    slayyyPrimed: false,
    outcome: "in_progress"
  };
}
function skipTutorial(state) {
  const tutorial = state.tutorial;
  if (tutorial === null || tutorial.outcome !== "in_progress") return state;
  return { ...state, tutorial: { ...tutorial, outcome: "skipped" } };
}
function resolveLane(state, lane) {
  if (lane === "player") return occupiedLane(state);
  if (lane === "adjacent") {
    const here = occupiedLane(state);
    return here === TUNING.lane.count - 1 ? here - 1 : here + 1;
  }
  return lane;
}
function currentBeat(tutorial) {
  const script = TUTORIAL_SCRIPT[tutorial.lesson];
  return script.beats[tutorial.beatIndex] ?? null;
}
function advanceTutorialDirector(state) {
  const tutorial = state.tutorial;
  if (tutorial === null || state.phase !== "running") return state;
  if (tutorial.outcome !== "in_progress") return state;
  if (tutorial.nextCueAtUnits === null || state.distanceUnits < tutorial.nextCueAtUnits) return state;
  const beat = currentBeat(tutorial);
  if (beat === null) return { ...state, tutorial: { ...tutorial, nextCueAtUnits: null } };
  const overshoot = state.distanceUnits - tutorial.nextCueAtUnits;
  const origin = TUNING.world.spawnLookaheadUnits - overshoot;
  const obstacles = [];
  const pawTokens = [];
  const cueObstacleIds = [];
  const cuePawTokenIds = [];
  let nextObstacleId = state.nextObstacleId;
  let nextPawTokenId = state.nextPawTokenId;
  for (const prop of beat.obstacles) {
    obstacles.push(Object.freeze({
      id: nextObstacleId,
      kind: prop.kind,
      lane: resolveLane(state, prop.lane),
      distanceUnits: origin + prop.offsetUnits,
      lengthUnits: TUNING.obstacle.defaultLengthUnits,
      outcome: "pending"
    }));
    cueObstacleIds.push(nextObstacleId);
    nextObstacleId++;
  }
  for (const prop of beat.pawTokens) {
    const lane = resolveLane(state, prop.lane);
    pawTokens.push(Object.freeze({
      id: nextPawTokenId,
      lane,
      laneOffset: lane,
      distanceUnits: origin + prop.offsetUnits,
      outcome: "pending"
    }));
    cuePawTokenIds.push(nextPawTokenId);
    nextPawTokenId++;
  }
  return {
    ...state,
    obstacles: [...state.obstacles, ...obstacles],
    pawTokens: [...state.pawTokens, ...pawTokens],
    nextObstacleId,
    nextPawTokenId,
    tutorial: {
      ...tutorial,
      nextCueAtUnits: null,
      cueObstacleIds,
      cuePawTokenIds,
      cueOverlapInLane: false,
      cueJumped: false
    }
  };
}
function cueObstaclesResolved(state, tutorial) {
  if (tutorial.cueObstacleIds.length === 0) return false;
  return tutorial.cueObstacleIds.every((id) => {
    const obstacle = state.obstacles.find((candidate) => candidate.id === id);
    return obstacle === void 0 || obstacle.outcome !== "pending";
  });
}
function cueTokensGone(state, tutorial) {
  if (tutorial.cuePawTokenIds.length === 0) return false;
  return tutorial.cuePawTokenIds.every((id) => !state.pawTokens.some((token) => token.id === id));
}
function observeCue(state, tutorial) {
  if (tutorial.cueObstacleIds.length === 0) return tutorial;
  let overlapInLane = tutorial.cueOverlapInLane;
  let jumped = tutorial.cueJumped;
  for (const id of tutorial.cueObstacleIds) {
    const obstacle = state.obstacles.find((candidate) => candidate.id === id);
    if (obstacle === void 0) continue;
    if (!overlapsLongitudinally(obstacle)) continue;
    if (occupiedLane(state) !== obstacle.lane) continue;
    overlapInLane = true;
    if (isAirborne(state)) jumped = true;
  }
  if (overlapInLane === tutorial.cueOverlapInLane && jumped === tutorial.cueJumped) return tutorial;
  return { ...tutorial, cueOverlapInLane: overlapInLane, cueJumped: jumped };
}
var WAIT = { passed: false, correction: null, retry: false };
var PASS = { passed: true, correction: null, retry: false };
function fail(correction) {
  return { passed: false, correction, retry: true };
}
function judge(state, before, tutorial, collectedIds) {
  switch (tutorial.lesson) {
    case "intro":
      return tutorial.lessonElapsedMs >= TUNING.tutorial.introDwellMs ? PASS : WAIT;
    // The settled lane, not the transition: the lesson is "you can be in the
    // left lane", and a change that was started and interrupted did not teach it.
    case "move_left":
      return state.lane === LANE_LEFT ? PASS : WAIT;
    case "move_right":
      return state.lane === LANE_RIGHT ? PASS : WAIT;
    /*
     * The cone. Being in its lane at all is the failure, however it happened —
     * which is exactly the rule the player has to learn, and why jumping is not
     * special-cased anywhere in the domain. `isDamaging` already refuses to
     * clear a lane blocker for an airborne player; all this does is notice, and
     * then say the useful thing about it.
     */
    case "dodge_cone":
      if (!cueObstaclesResolved(state, tutorial)) return WAIT;
      if (!tutorial.cueOverlapInLane) return PASS;
      return fail(tutorial.cueJumped ? "jumped_at_cone" : "contacted_cone");
    /*
     * The barrier, and the point of the pair: going around it is not an error
     * the rules punish, but it is not the lesson either, so it is corrected
     * rather than accepted.
     */
    case "jump_barrier":
      if (!cueObstaclesResolved(state, tutorial)) return WAIT;
      if (!tutorial.cueOverlapInLane) return fail("dodged_barrier");
      if (!tutorial.cueJumped) return fail("contacted_barrier");
      return PASS;
    // By id. Not by `runPaws`, which only agrees while nothing else can grant one.
    case "collect_paw":
      if (tutorial.cuePawTokenIds.some((id) => collectedIds.includes(id))) return PASS;
      if (cueTokensGone(state, tutorial)) return fail("missed_paw");
      return WAIT;
    // The real mechanic, through the real control. The meter was filled for
    // this lesson; the decision to spend it is still the player's.
    case "activate_slayyy":
      return state.slayyyActivations > before.slayyyActivations ? PASS : WAIT;
    case "final_practice":
      return WAIT;
    case "complete":
      return WAIT;
  }
}
function openLesson(state, tutorial, lesson) {
  const script = TUTORIAL_SCRIPT[lesson];
  return {
    ...tutorial,
    lesson,
    lessonElapsedMs: 0,
    lessonCorrections: 0,
    correction: null,
    beatIndex: 0,
    nextCueAtUnits: script.beats.length === 0 ? null : state.distanceUnits + TUNING.tutorial.firstCueUnits,
    cueObstacleIds: [],
    cuePawTokenIds: [],
    cueOverlapInLane: false,
    cueJumped: false,
    celebrateRemainingMs: TUNING.tutorial.celebrateMs,
    outcome: lesson === "complete" ? "completed" : tutorial.outcome
  };
}
function retryBeat(state, tutorial) {
  return {
    ...tutorial,
    nextCueAtUnits: state.distanceUnits + TUNING.tutorial.repeatGapUnits,
    cueObstacleIds: [],
    cuePawTokenIds: [],
    cueOverlapInLane: false,
    cueJumped: false
  };
}
function advancePractice(state, tutorial) {
  const script = TUTORIAL_SCRIPT.final_practice;
  const beat = currentBeat(tutorial);
  if (beat === null) return openLesson(state, tutorial, "complete");
  const placed = tutorial.cueObstacleIds.length > 0 || tutorial.cuePawTokenIds.length > 0;
  if (!placed) return tutorial;
  const settled = beat.obstacles.length > 0 ? cueObstaclesResolved(state, tutorial) : cueTokensGone(state, tutorial);
  if (!settled) return tutorial;
  const beatIndex = tutorial.beatIndex + 1;
  if (beatIndex >= script.beats.length) return openLesson(state, tutorial, "complete");
  return {
    ...tutorial,
    beatIndex,
    nextCueAtUnits: state.distanceUnits + TUNING.tutorial.firstCueUnits,
    cueObstacleIds: [],
    cuePawTokenIds: [],
    cueOverlapInLane: false,
    cueJumped: false
  };
}
function awaitsInput(lesson) {
  return lesson === "move_left" || lesson === "move_right" || lesson === "activate_slayyy";
}
function advanceTutorial(state, before, collectedIds, deltaMs) {
  const existing = state.tutorial;
  if (existing === null || existing.outcome !== "in_progress") return state;
  let tutorial = observeCue(state, existing);
  tutorial = {
    ...tutorial,
    lessonElapsedMs: tutorial.lessonElapsedMs + deltaMs,
    celebrateRemainingMs: Math.max(0, tutorial.celebrateRemainingMs - deltaMs)
  };
  if (tutorial.lesson === "activate_slayyy" && !tutorial.slayyyPrimed) {
    return {
      ...state,
      slayyy: primeSlayyy(state.slayyy),
      tutorial: { ...tutorial, slayyyPrimed: true }
    };
  }
  if (tutorial.lesson === "final_practice") {
    return { ...state, tutorial: advancePractice(state, tutorial) };
  }
  const verdict = judge(state, before, tutorial, collectedIds);
  if (verdict.passed) {
    const next = TUTORIAL_LESSON_ORDER[tutorialLessonIndex(tutorial.lesson) + 1];
    return { ...state, tutorial: openLesson(state, tutorial, next ?? "complete") };
  }
  if (verdict.correction !== null) {
    const corrected = {
      ...tutorial,
      correction: verdict.correction,
      correctionCount: tutorial.correctionCount + 1,
      lessonCorrections: tutorial.lessonCorrections + 1
    };
    return { ...state, tutorial: verdict.retry ? retryBeat(state, corrected) : corrected };
  }
  if (awaitsInput(tutorial.lesson)) {
    const due = TUNING.tutorial.repromptMs * (tutorial.lessonCorrections + 1);
    if (tutorial.lessonElapsedMs >= due) {
      return {
        ...state,
        tutorial: {
          ...tutorial,
          correction: "no_input",
          correctionCount: tutorial.correctionCount + 1,
          lessonCorrections: tutorial.lessonCorrections + 1
        }
      };
    }
  }
  return { ...state, tutorial };
}

// game/domain/state.ts
function createRunState({ seed, loliCyclePaws = 0, mode = "run" }) {
  if (!Number.isInteger(seed) || seed < 0 || seed > MAX_SEED) {
    throw new RangeError(
      `createRunState: seed must be a whole number from 0 to ${MAX_SEED} (got ${String(seed)})`
    );
  }
  const startLane = TUNING.lane.startIndex;
  if (!isLaneIndex(startLane)) {
    throw new RangeError(`createRunState: lane.startIndex ${startLane} is not a lane`);
  }
  if (!Number.isInteger(loliCyclePaws) || loliCyclePaws < 0 || loliCyclePaws >= TUNING.paw.loliThreshold) {
    throw new RangeError(
      `createRunState: loliCyclePaws must be a whole number below the ${TUNING.paw.loliThreshold} threshold (got ${String(loliCyclePaws)})`
    );
  }
  return sealState({
    phase: "ready",
    elapsedMs: 0,
    readyRemainingMs: TUNING.run.readyMs,
    lane: startLane,
    laneTransition: null,
    jumpElapsedMs: null,
    buffered: null,
    resumePhase: "ready",
    rng: createRngState(seed),
    seed,
    hearts: TUNING.hearts.start,
    invulnRemainingMs: 0,
    obstacles: [],
    distanceUnits: 0,
    nextObstacleId: 0,
    spawn: {
      // The first pattern is scheduled so that nothing can reach the player
      // before the approved protected interval. Reachability, not spawn time:
      // an obstacle exists earlier so it can be seen approaching.
      nextAtUnits: firstHazardDistanceUnits(),
      recentPatternIds: []
    },
    nearMissCount: 0,
    coneSafePasses: 0,
    score: EMPTY_SCORE,
    pawTokens: [],
    nextPawTokenId: 0,
    // The first group is scheduled with the first hazard, so the opening is not
    // an empty road with nothing to reach for.
    nextPawAtUnits: firstHazardDistanceUnits(),
    runPaws: 0,
    loliCyclePaws,
    loli: EMPTY_LOLI,
    slayyy: EMPTY_SLAYYY,
    loliActivations: 0,
    slayyyActivations: 0,
    tutorial: mode === "tutorial" ? createTutorialState() : null
  });
}
function sealState(state) {
  Object.freeze(state.obstacles);
  Object.freeze(state.spawn.recentPatternIds);
  Object.freeze(state.spawn);
  Object.freeze(state.rng.pattern);
  Object.freeze(state.rng.collectible);
  Object.freeze(state.rng.cosmetic);
  Object.freeze(state.rng);
  if (state.laneTransition !== null) Object.freeze(state.laneTransition);
  if (state.buffered !== null) {
    Object.freeze(state.buffered.event);
    Object.freeze(state.buffered);
  }
  Object.freeze(state.pawTokens);
  Object.freeze(state.score);
  Object.freeze(state.loli);
  Object.freeze(state.slayyy);
  if (state.tutorial !== null) {
    Object.freeze(state.tutorial.cueObstacleIds);
    Object.freeze(state.tutorial.cuePawTokenIds);
    Object.freeze(state.tutorial);
  }
  return Object.freeze(state);
}

// game/domain/input.ts
function isActionable(state, event) {
  switch (event.type) {
    case "move_left":
    case "move_right":
      return canStartLaneChange(state);
    case "jump":
      return canJump(state);
    case "slayyy":
      return canActivateSlayyy(state);
    case "pause":
    case "resume":
    case "tutorial_skip":
      return true;
  }
}
function applyActionable(state, event) {
  switch (event.type) {
    case "move_left":
      return startLaneChange(state, -1);
    case "move_right":
      return startLaneChange(state, 1);
    case "jump":
      return startJump(state);
    case "slayyy":
      return activateSlayyy(state);
    case "pause":
    case "resume":
    case "tutorial_skip":
      return state;
  }
}
function isBufferable(event) {
  return event.type === "move_left" || event.type === "move_right" || event.type === "jump";
}
function applyInput(state, event) {
  if (isActionable(state, event)) {
    const applied = applyActionable(state, event);
    return applied;
  }
  if (!isBufferable(event)) return state;
  return { ...state, buffered: { event, ageMs: 0 } };
}
function advanceBuffer(state, deltaMs) {
  const buffered = state.buffered;
  if (buffered === null) return state;
  if (isActionable(state, buffered.event)) {
    const applied = applyActionable({ ...state, buffered: null }, buffered.event);
    return applied;
  }
  const ageMs = buffered.ageMs + deltaMs;
  if (ageMs >= TUNING.input.bufferMs) {
    return { ...state, buffered: null };
  }
  return { ...state, buffered: { ...buffered, ageMs } };
}

// game/domain/collectibles.ts
function advancePawTokens(state, deltaMs) {
  if (deltaMs === 0 || state.pawTokens.length === 0) return state;
  const moved = scrollDeltaUnits(state.elapsedMs, deltaMs);
  const pawTokens = [];
  for (const token of state.pawTokens) {
    const distanceUnits = token.distanceUnits - moved;
    if (distanceUnits + TUNING.paw.lengthUnits < -TUNING.world.despawnBehindUnits) continue;
    pawTokens.push(Object.freeze({ ...token, distanceUnits }));
  }
  return { ...state, pawTokens };
}
function advancePawSpawning(state) {
  if (state.phase !== "running") return state;
  if (state.tutorial !== null) return state;
  let next = state;
  let guard = 0;
  while (next.distanceUnits >= next.nextPawAtUnits && guard < MAX_GROUPS_PER_STEP) {
    next = emitPawGroup(next);
    guard++;
  }
  return next;
}
var MAX_GROUPS_PER_STEP = TUNING.sim.maxCatchUpSteps;
function clearLanes(state, fromUnits, toUnits) {
  const clear = new Set(
    Array.from({ length: TUNING.lane.count }, (_, index) => index)
  );
  for (const obstacle of state.obstacles) {
    const overlaps = obstacle.distanceUnits < toUnits && obstacle.distanceUnits + obstacle.lengthUnits > fromUnits;
    if (overlaps) clear.delete(obstacle.lane);
  }
  return clear;
}
function sitsInsideABlocker(state, token) {
  const lane = Math.round(token.laneOffset);
  return state.obstacles.some((obstacle) => obstacle.kind === "lane_blocking" && obstacle.lane === lane && obstacle.distanceUnits < token.distanceUnits + TUNING.paw.lengthUnits && obstacle.distanceUnits + obstacle.lengthUnits > token.distanceUnits);
}
function dropBlockedTokens(state) {
  if (state.pawTokens.length === 0 || state.obstacles.length === 0) return state;
  const pawTokens = state.pawTokens.filter((token) => !sitsInsideABlocker(state, token));
  return pawTokens.length === state.pawTokens.length ? state : { ...state, pawTokens };
}
function emitPawGroup(state) {
  const overshoot = state.distanceUnits - state.nextPawAtUnits;
  const origin = TUNING.world.spawnLookaheadUnits - overshoot;
  const countDraw = nextIntInclusive(state.rng.collectible, 1, TUNING.paw.perPatternMax);
  const count = countDraw.value;
  const span = TUNING.paw.spacingUnits * (count - 1) + TUNING.paw.lengthUnits;
  const clear = clearLanes(state, origin, origin + span);
  const laneDraw = nextIntInclusive(countDraw.state, 0, TUNING.lane.count - 1);
  let lane = laneDraw.value;
  for (let i = 0; i < TUNING.lane.count; i++) {
    const candidate = (laneDraw.value + i) % TUNING.lane.count;
    if (clear.has(candidate)) {
      lane = candidate;
      break;
    }
  }
  const spawned = [];
  let nextPawTokenId = state.nextPawTokenId;
  for (let i = 0; i < count; i++) {
    spawned.push(Object.freeze({
      id: nextPawTokenId,
      lane,
      laneOffset: lane,
      distanceUnits: origin + TUNING.paw.spacingUnits * i,
      outcome: "pending"
    }));
    nextPawTokenId++;
  }
  return {
    ...state,
    pawTokens: [...state.pawTokens, ...spawned],
    nextPawTokenId,
    nextPawAtUnits: state.nextPawAtUnits + TUNING.paw.groupGapUnits,
    rng: { ...state.rng, collectible: laneDraw.state }
  };
}
function overlapsLongitudinally2(token) {
  const half = TUNING.collision.playerLengthUnits / 2;
  return token.distanceUnits < half && token.distanceUnits + TUNING.paw.lengthUnits > -half;
}
var NOTHING_COLLECTED = Object.freeze([]);
function resolvePawTokens(state) {
  if (state.pawTokens.length === 0) {
    return { state, collected: 0, collectedIds: NOTHING_COLLECTED };
  }
  const position = lanePosition(state);
  const pawTokens = [];
  const collectedIds = [];
  let collected = 0;
  let changed = false;
  for (const token of state.pawTokens) {
    if (token.outcome !== "pending") {
      pawTokens.push(token);
      continue;
    }
    const lateral = Math.abs(position - token.laneOffset);
    const takes = overlapsLongitudinally2(token) && lateral <= TUNING.paw.collectLateralUnits;
    if (takes) {
      collected++;
      collectedIds.push(token.id);
      changed = true;
      pawTokens.push(Object.freeze({ ...token, outcome: "collected" }));
      continue;
    }
    pawTokens.push(token);
  }
  if (!changed) return { state, collected: 0, collectedIds: NOTHING_COLLECTED };
  return {
    state: { ...state, pawTokens: pawTokens.filter((token) => token.outcome !== "collected") },
    collected,
    collectedIds: Object.freeze(collectedIds)
  };
}

// game/domain/step.ts
function step(state, inputs, deltaMs) {
  if (!Number.isFinite(deltaMs) || deltaMs < 0) {
    throw new RangeError(`step: deltaMs must be a finite, non-negative number (got ${String(deltaMs)})`);
  }
  let next = state;
  if (next.phase === "ended") return sealState(next);
  for (const input of inputs) {
    if (!isInputEvent(input)) {
      throw new TypeError(`step: not an input event (got ${JSON.stringify(input) ?? String(input)})`);
    }
    if (isControlInput(input)) {
      next = applyControlInput(next, input);
      continue;
    }
    if (next.phase === "paused") continue;
    next = applyInput(next, input);
  }
  if (next.phase === "paused") {
    return sealState(next);
  }
  const { state: afterReady, runningDeltaMs } = advanceReady(next, deltaMs);
  next = afterReady;
  next = advanceLaneTransition(next, deltaMs);
  next = advanceJump(next, deltaMs);
  next = advanceBuffer(next, deltaMs);
  if (runningDeltaMs !== 0) {
    next = { ...next, elapsedMs: next.elapsedMs + runningDeltaMs };
  }
  const distanceBefore = next.distanceUnits;
  next = advanceObstacles(next, runningDeltaMs);
  next = advanceSpawning(next);
  next = advancePawTokens(next, runningDeltaMs);
  next = advancePawSpawning(next);
  next = advanceTutorialDirector(next);
  next = advanceLoli(next, runningDeltaMs);
  next = applyMagnet(next, runningDeltaMs);
  next = dropBlockedTokens(next);
  const movedUnits = next.distanceUnits - distanceBefore;
  if (movedUnits > 0) {
    next = { ...next, score: earnMilli(next, "distanceMilli", distanceMilliFor(movedUnits)) };
  }
  const resolved = resolveCollisions(next);
  next = resolved.state;
  if (resolved.nearMisses > 0) {
    next = { ...next, nearMissCount: next.nearMissCount + resolved.nearMisses };
  }
  if (resolved.coneSafePasses > 0) {
    next = { ...next, coneSafePasses: next.coneSafePasses + resolved.coneSafePasses };
  }
  if (resolved.heartsLost > 0 && next.tutorial === null) {
    const hearts = Math.max(0, next.hearts - resolved.heartsLost);
    next = {
      ...next,
      hearts,
      // A hit starts the invulnerability window and changes nothing else. What
      // a collision does to an in-progress lane change, and whether it dips the
      // scroll speed, is CR-2 and still OPEN — so it does neither.
      invulnRemainingMs: TUNING.invuln.postHitMs
    };
    if (hearts === 0) {
      next = { ...next, phase: "ended", resumePhase: "running" };
    }
  } else if (next.invulnRemainingMs > 0) {
    next = { ...next, invulnRemainingMs: Math.max(0, next.invulnRemainingMs - runningDeltaMs) };
  }
  let collectedIds = NOTHING_COLLECTED;
  if (next.phase !== "ended") {
    const picked = resolvePawTokens(next);
    next = picked.state;
    collectedIds = picked.collectedIds;
    if (picked.collected > 0) {
      const cycle = applyPawsToCycle(next.loliCyclePaws, picked.collected);
      next = {
        ...next,
        runPaws: next.runPaws + picked.collected,
        loliCyclePaws: cycle.loliCyclePaws,
        score: earnPoints(next, "collectionMilli", TUNING.score.perPaw * picked.collected),
        slayyy: chargeFromPaws(next.slayyy, picked.collected)
      };
      next = earnLoliBonuses(next, cycle.earned);
    }
  }
  if (next.phase !== "ended") {
    next = { ...next, slayyy: chargeFromTime(next, runningDeltaMs) };
    next = { ...next, slayyy: advanceSlayyy(next, runningDeltaMs) };
  }
  next = advanceTutorial(next, state, collectedIds, runningDeltaMs);
  if (next.phase === "ended") {
    next = clearLoli(next);
    if (next.slayyy.phase === "active") {
      next = { ...next, slayyy: { ...next.slayyy, phase: "cooldown", activeRemainingMs: 0 } };
    }
  }
  return sealState(next);
}
function isControlInput(input) {
  return input.type === "pause" || input.type === "resume" || input.type === "tutorial_skip";
}
var INPUT_TYPES = /* @__PURE__ */ new Set([
  "move_left",
  "move_right",
  "jump",
  "slayyy",
  "pause",
  "resume",
  "tutorial_skip"
]);
function isInputEvent(input) {
  return typeof input === "object" && input !== null && INPUT_TYPES.has(input.type);
}
function applyControlInput(state, input) {
  if (input.type === "tutorial_skip") return skipTutorial(state);
  if (input.type === "pause") {
    if (state.phase === "paused") return state;
    return { ...state, phase: "paused", resumePhase: state.phase };
  }
  if (input.type === "resume") {
    if (state.phase !== "paused") return state;
    return { ...state, phase: state.resumePhase };
  }
  return state;
}
function advanceReady(state, deltaMs) {
  if (state.phase === "running") {
    return { state, runningDeltaMs: deltaMs };
  }
  const remaining = state.readyRemainingMs - deltaMs;
  if (remaining > 0) {
    return { state: { ...state, readyRemainingMs: remaining }, runningDeltaMs: 0 };
  }
  return {
    state: { ...state, phase: "running", readyRemainingMs: 0, resumePhase: "running" },
    runningDeltaMs: -remaining
  };
}

// game/domain/version.ts
var DOMAIN_VERSION = "1";

// game/domain/input-codes.ts
var INPUT_FORMAT_VERSION = 1;
var MAX_STREAM_STEPS = 432e3;
var MAX_STREAM_EVENTS = 2e4;
var STEP_INPUT_CODES = Object.freeze({
  move_left: 0,
  move_right: 1,
  jump: 2,
  slayyy: 3,
  pause: 4,
  resume: 5,
  tutorial_skip: 6
});
var CONTROL_INVOCATION_CODES = Object.freeze({
  pause: 7,
  resume: 8,
  tutorial_skip: 9
});
var STEP_INPUT_BY_CODE = Object.freeze([
  "move_left",
  "move_right",
  "jump",
  "slayyy",
  "pause",
  "resume",
  "tutorial_skip"
]);
var CONTROL_BY_CODE = Object.freeze(["pause", "resume", "tutorial_skip"]);
function decodeInputCode(code) {
  if (typeof code !== "number" || !Number.isInteger(code)) return null;
  const stepType = STEP_INPUT_BY_CODE[code];
  if (stepType !== void 0) return { call: "step", input: { type: stepType } };
  const controlType = CONTROL_BY_CODE[code - CONTROL_INVOCATION_CODES.pause];
  if (controlType !== void 0) return { call: "control", input: { type: controlType } };
  return null;
}

// game/domain/escape.ts
var GRID_MS = TUNING.escape.solverGridMs;
var MAX_SEARCH_STEPS = TUNING.escape.solverMaxSteps;

// game/replay/replay.ts
var REPLAY_PROTOCOL = 1;
var refuse = (status) => ({ protocol: REPLAY_PROTOCOL, domain_version: DOMAIN_VERSION, status });
function isRecord(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function isWhole(value, min, max) {
  return typeof value === "number" && Number.isInteger(value) && value >= min && value <= max;
}
function parse(doc) {
  if (!isRecord(doc)) return "invalid_input";
  if (doc.protocol !== REPLAY_PROTOCOL) return "unsupported_version";
  if (doc.domain_version !== DOMAIN_VERSION) return "unsupported_version";
  const input = doc.input;
  if (!isRecord(input)) return "invalid_input";
  if (input.format_version !== INPUT_FORMAT_VERSION) return "unsupported_version";
  if (input.domain_version !== void 0 && input.domain_version !== DOMAIN_VERSION) return "unsupported_version";
  if (doc.mode !== "run") return "invalid_input";
  if (!isWhole(doc.seed, 0, 4294967295)) return "invalid_input";
  if (!isWhole(doc.start_loli_cycle_paws, 0, TUNING.paw.loliThreshold - 1)) return "invalid_input";
  if (!isWhole(input.total_steps, 0, MAX_STREAM_STEPS)) return "invalid_input";
  const events = input.events;
  if (!Array.isArray(events) || events.length > MAX_STREAM_EVENTS) return "invalid_input";
  const totalSteps = input.total_steps;
  const slots = /* @__PURE__ */ new Map();
  let position = 0;
  for (const event of events) {
    if (!Array.isArray(event) || event.length !== 2) return "invalid_input";
    const [gap, code] = event;
    if (!isWhole(gap, 0, MAX_STREAM_STEPS)) return "invalid_input";
    position += gap;
    const decoded = decodeInputCode(code);
    if (decoded === null) return "invalid_input";
    let slot = slots.get(position);
    if (slot === void 0) {
      slot = { controls: [], inputs: [] };
      slots.set(position, slot);
    }
    if (decoded.call === "step") {
      if (position >= totalSteps) return "invalid_input";
      slot.inputs.push(decoded.input);
    } else {
      if (position > totalSteps || slot.inputs.length > 0) return "invalid_input";
      slot.controls.push(decoded.input);
    }
  }
  return { seed: doc.seed, startLoliCyclePaws: doc.start_loli_cycle_paws, totalSteps, slots };
}
function replayState(doc) {
  const parsed = parse(doc);
  if (typeof parsed === "string") return { status: parsed };
  let state = createRunState({ seed: parsed.seed, loliCyclePaws: parsed.startLoliCyclePaws, mode: "run" });
  let stepsConsumed = 0;
  const none = [];
  for (let position = 0; position <= parsed.totalSteps && state.phase !== "ended"; position++) {
    const slot = parsed.slots.get(position);
    for (const control of slot?.controls ?? none) {
      state = step(state, [control], 0);
      if (state.phase === "ended") break;
    }
    if (state.phase === "ended" || position === parsed.totalSteps) break;
    state = step(state, slot?.inputs ?? none, STEP_MS);
    stepsConsumed++;
  }
  return { status: "completed", state, stepsConsumed };
}
function replayRun(doc) {
  const replayed = replayState(doc);
  if (replayed.status !== "completed") return refuse(replayed.status);
  const { state, stepsConsumed } = replayed;
  return {
    protocol: REPLAY_PROTOCOL,
    domain_version: DOMAIN_VERSION,
    status: "completed",
    final: {
      ended: state.phase === "ended",
      elapsed_ms_floor: Math.floor(state.elapsedMs),
      score: scoreTotal(state),
      run_paws: state.runPaws,
      steps_consumed: stepsConsumed
    },
    facts: {
      cone_safe_passes: state.coneSafePasses,
      near_misses: state.nearMissCount,
      slayyy_activations: state.slayyyActivations,
      loli_activations: state.loliActivations
    }
  };
}

// game/replay/cli.ts
async function readStdin() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  return Buffer.concat(chunks).toString("utf8");
}
async function main() {
  const text = await readStdin();
  let doc;
  try {
    doc = JSON.parse(text);
  } catch {
    doc = void 0;
  }
  process.stdout.write(`${JSON.stringify(replayRun(doc))}
`);
}
main().catch((error) => {
  process.stderr.write(`replay crashed: ${error instanceof Error ? error.name : "unknown"}
`);
  process.exitCode = 1;
});
