import { describe, expect, it } from "vitest";
import { nextStages } from "./recruitment";

describe("nextStages", () => {
    it.each([
        ["applied", ["screening", "rejected"]],
        ["screening", ["interview", "rejected"]],
        ["interview", ["offer", "rejected"]],
        ["offer", ["accepted", "rejected"]],
        ["accepted", []],
        ["rejected", []],
        ["tidak-dikenal", []],
    ])("%s can move to %j", (stage, expected) => {
        expect(nextStages(stage)).toEqual(expected);
    });
});
