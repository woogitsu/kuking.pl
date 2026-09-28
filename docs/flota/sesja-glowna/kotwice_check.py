import sys, os
p = sys.argv[1]
src = open(p).read()
cut = src.index("\nrun_test(COLLECTION_TEST, True)")
g = {"__file__": os.path.abspath(p), "__name__": "kotwice"}
exec(compile(src[:cut], p, "exec"), g)
print("OK kotwice:", len(g["checks"]))
