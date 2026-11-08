/**
 * contractFileIO: filesystem-access layer for the contract validation gate.
 *
 * Pure data-access concern. Reads files and directories from disk and parses
 * their contents. Contains no business/validation logic; callers in
 * validate-contracts.js decide what the read data means for contract validity.
 *
 * Exposes:
 *   fileExists(p)                                  -> boolean
 *   pathExists(p)                                  -> boolean
 *   filesEqual(left, right)                        -> boolean
 *   loadJson(p)                                    -> parsed JSON (throws on bad JSON)
 *   findJsonSchemaFiles(dir)                       -> string[] of *.schema.json paths
 *   fileContainsAnnotation(filePath, id, name?)    -> boolean
 *   fileContainsParityAnnotation(filePath, id)     -> boolean
 *   defaultServerContractsDir(checkoutRoot)        -> string
 */
// allow-no-test-found: shared filesystem/repository adapter covered through the real CLI in tests-js/contract-validator.test.js, including linked worktrees, missing owners, drift, and explicit overrides.

const fs = require("fs");
const path = require("path");
const { spawnSync } = require("child_process");

/** A repository lookup failed; retain Git's diagnostic and the explicit remedy. */
class ContractOwnerPathError extends Error {
  constructor(detail, cause) {
    super(`Cannot discover the canonical contract checkout: ${detail}`, { cause });
    this.name = "ContractOwnerPathError";
    this.code = "CONTRACT_OWNER_PATH";
    this.hint = "Repair the checkout's Git metadata or set ABJ404_SERVER_CONTRACTS_DIR.";
  }
}

/**
 * Locate the owning repository beside Git's primary checkout, including when
 * this gate runs from a linked landing worktree. Source exports without Git
 * metadata retain the ordinary sibling-clone layout. Never search for another
 * matching copy: missing or divergent owning files must still fail validation.
 * @param {string} checkoutRoot Absolute path to the checkout containing the gate.
 * @returns {string} Absolute path to the server's contracts directory.
 */
function defaultServerContractsDir(checkoutRoot) {
  let primary = path.resolve(checkoutRoot);
  if (pathExists(path.join(primary, ".git"))) {
    const result = spawnSync("git", ["worktree", "list", "--porcelain", "-z"], {
      cwd: primary, encoding: "utf8", timeout: 5000,
    });
    const first = (result.stdout || "").split("\0")[0];
    if (result.error || result.status !== 0 || !first.startsWith("worktree ")) {
      throw new ContractOwnerPathError(
        result.error?.message || result.stderr?.trim() || `git worktree list exited ${result.status}`,
        result.error
      );
    }
    primary = first.slice("worktree ".length);
  }
  return path.resolve(primary, "..", "404-solution-server", "contracts");
}

function fileExists(p) {
  try {
    return fs.statSync(p).isFile();
  } catch {
    return false; // allow-silent-catch: stat failure means file does not exist
  }
}

function pathExists(p) {
  try {
    fs.statSync(p);
    return true;
  } catch {
    return false; // allow-silent-catch: stat failure means path is unavailable
  }
}

function filesEqual(left, right) {
  return fs.readFileSync(left).equals(fs.readFileSync(right));
}

function loadJson(p) {
  return JSON.parse(fs.readFileSync(p, "utf8"));
}

function findJsonSchemaFiles(dir) {
  const results = [];
  if (!fs.existsSync(dir)) return results;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      results.push(...findJsonSchemaFiles(full));
    } else if (entry.name.endsWith(".schema.json")) {
      results.push(full);
    }
  }
  return results;
}

function fileContainsAnnotation(filePath, contractId, annotationName = "contract") {
  const content = fs.readFileSync(filePath, "utf8");
  const pattern = new RegExp(`@${annotationName}\\s+${contractId.replace(/-/g, "\\-")}\\b`);
  return pattern.test(content);
}

function fileContainsParityAnnotation(filePath, contractId) {
  const content = fs.readFileSync(filePath, "utf8");
  const pattern = new RegExp(`@parityTest\\s+${contractId.replace(/-/g, "\\-")}\\b`);
  return pattern.test(content);
}

module.exports = {
  defaultServerContractsDir,
  fileExists,
  pathExists,
  filesEqual,
  loadJson,
  findJsonSchemaFiles,
  fileContainsAnnotation,
  fileContainsParityAnnotation,
};
