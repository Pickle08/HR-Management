// Cermin dari CandidateStage::canMoveTo() di backend. Kalau enum berubah, ini harus ikut.
const ORDER = ["applied", "screening", "interview", "offer", "accepted"];

export function nextStages(stage) {
    const index = ORDER.indexOf(stage);

    if (index === -1 || stage === "accepted") return [];

    return [ORDER[index + 1], "rejected"];
}
