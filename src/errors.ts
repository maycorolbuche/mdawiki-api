export class HttpError extends Error {
  constructor(public readonly status: number, message: string) { super(message); }
}
export function fail(status: number, message: string): never {
  throw new HttpError(status, message);
}
